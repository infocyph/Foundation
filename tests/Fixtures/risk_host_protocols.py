"""Verify actual TLS H1/H2/H3 ingress with no protocol fallback."""
import asyncio
import json
import ssl
import sys
from pathlib import Path

import httpx
from aioquic.asyncio.client import connect
from aioquic.asyncio.protocol import QuicConnectionProtocol
from aioquic.h3.connection import H3_ALPN, H3Connection
from aioquic.h3.events import DataReceived, HeadersReceived
from aioquic.quic.configuration import QuicConfiguration
from aioquic.quic.events import ConnectionTerminated, ProtocolNegotiated

AUTHORITY = "localhost:19104"
CASES = (("GET", "/ordinary", 200), ("POST", "/login", 200),
         ("POST", "/challenge", 429), ("POST", "/deny", 403))
MAX_BODY = 8192


def verify(protocol, path, expected, status, headers, body):
    if status != expected or len(body) > MAX_BODY:
        raise RuntimeError(f"{protocol} {path}: unexpected response {status}")
    data = json.loads(body)
    if path == "/ordinary":
        if not (data["pid"] == data["runtime_pid"] and data["request_id"] and data["sapi"] == "cli"):
            raise RuntimeError("Ordinary route did not reach the Runwire host")
    elif not (data["status"] == status and data["sapi"] == "fpm-fcgi"
              and headers.get("cache-control") == "no-store"):
        raise RuntimeError("Protected response did not preserve FPM status and cache policy")
    print(f"{protocol} {path}: {status}", flush=True)
    return {"protocol": protocol, "route": path, "status": status, "pid": data["pid"]}


class H3Client(QuicConnectionProtocol):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.http = H3Connection(self._quic)
        self.pending = {}
        self.negotiated = None

    def quic_event_received(self, event):
        if isinstance(event, ProtocolNegotiated):
            self.negotiated = event.alpn_protocol
        for item in self.http.handle_event(event):
            if isinstance(item, (HeadersReceived, DataReceived)) and item.stream_id in self.pending:
                headers, body, future = self.pending[item.stream_id]
                if future.done():
                    continue
                if isinstance(item, HeadersReceived):
                    headers.update(item.headers)
                else:
                    body.extend(item.data)
                    if len(body) > MAX_BODY:
                        future.set_exception(RuntimeError("H3 response exceeds fixture bound"))
                        continue
                if item.stream_ended:
                    future.set_result((headers, bytes(body)))
        if isinstance(event, ConnectionTerminated):
            for _, _, future in self.pending.values():
                if not future.done():
                    future.set_exception(RuntimeError("H3 connection terminated"))

    async def request(self, method, path):
        stream = self._quic.get_next_available_stream_id()
        future = asyncio.get_running_loop().create_future()
        self.pending[stream] = ({}, bytearray(), future)
        self.http.send_headers(stream, [
            (b":method", method.encode()), (b":scheme", b"https"),
            (b":authority", AUTHORITY.encode()), (b":path", path.encode()),
            (b"content-length", b"0"),
        ], end_stream=True)
        self.transmit()
        try:
            return await asyncio.wait_for(future, 5)
        finally:
            self.pending.pop(stream, None)


async def qualify_h3(cafile):
    configuration = QuicConfiguration(is_client=True, alpn_protocols=H3_ALPN,
                                      server_name="localhost", cafile=cafile,
                                      verify_mode=ssl.CERT_REQUIRED)
    results = []
    async with connect("127.0.0.1", 19104, configuration=configuration,
                       create_protocol=H3Client) as client:
        if client.negotiated not in H3_ALPN:
            raise RuntimeError("HTTP/3 ALPN was not negotiated")
        for method, path, expected in CASES:
            headers, body = await client.request(method, path)
            status = int(headers.pop(b":status"))
            results.append(verify("HTTP/3", path, expected, status,
                                  {k.decode(): v.decode() for k, v in headers.items()}, body))
    return results


def main():
    cafile, evidence = sys.argv[1:]
    context = ssl.create_default_context(cafile=cafile)
    results = []
    for protocol in ("HTTP/1.1", "HTTP/2"):
        with httpx.Client(verify=context, trust_env=False, timeout=5,
                          http1=protocol == "HTTP/1.1", http2=protocol == "HTTP/2") as client:
            for method, path, expected in CASES:
                with client.stream(method, f"https://{AUTHORITY}{path}") as response:
                    if response.http_version != protocol:
                        raise RuntimeError(f"Expected {protocol}; negotiated {response.http_version}")
                    body = bytearray()
                    for chunk in response.iter_bytes():
                        body.extend(chunk)
                        if len(body) > MAX_BODY:
                            raise RuntimeError("Response exceeds fixture bound")
                    results.append(verify(protocol, path, expected, response.status_code,
                                          response.headers, bytes(body)))
    results.extend(asyncio.run(asyncio.wait_for(qualify_h3(cafile), timeout=30)))
    Path(evidence).write_text(json.dumps(results, indent=2) + "\n")


if __name__ == "__main__":
    main()
