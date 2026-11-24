"""Synthetic HTTPS provider; only used inside a network namespace without egress."""
import http.server
import json
import ssl
import sys
import time
import urllib.parse


class Provider(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, _format, *_args):
        pass

    def read_body(self):
        if self.headers.get("Transfer-Encoding", "").lower() == "chunked":
            chunks = bytearray()
            while True:
                size = int(self.rfile.readline(64).strip(), 16)
                if size == 0:
                    if self.rfile.readline(64) != b"\r\n":
                        raise ValueError("unexpected trailer")
                    return bytes(chunks)
                if size > 8192 - len(chunks):
                    raise ValueError("oversized request")
                chunks.extend(self.rfile.read(size))
                if self.rfile.read(2) != b"\r\n":
                    raise ValueError("invalid chunk delimiter")
        length = int(self.headers.get("Content-Length", "0"))
        if not 0 < length <= 8192:
            raise ValueError("invalid request size")
        return self.rfile.read(length)

    def do_POST(self):
        parsed = urllib.parse.urlsplit(self.path)
        form = urllib.parse.parse_qs(self.read_body().decode("ascii"), strict_parsing=True)
        if parsed.query != "fixed=1" or form != {
            "secret": ["fixture-secret"],
            "response": ["proof&value"],
            "remoteip": ["192.0.2.1"],
        }:
            self.send_error(400)
            return
        print(parsed.path, flush=True)
        if parsed.path in ("/timeout", "/cancel"):
            time.sleep(2)
        valid = {"success": True, "hostname": "app.example.test", "action": "login", "cdata": "tenant"}
        payload = json.dumps(valid).encode("ascii")
        status = 200
        if parsed.path == "/reject":
            payload = b'{"success":false}'
        elif parsed.path == "/redirect":
            status = 302
        elif parsed.path == "/oversize":
            payload = b"x" * 16385
        elif parsed.path == "/malformed":
            payload = b"{"
        elif parsed.path not in ("/accept", "/timeout", "/cancel"):
            self.send_error(404)
            return
        try:
            self.send_response(status)
            if status == 302:
                self.send_header("Location", "/should-not-follow?fixed=1")
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(payload)))
            self.send_header("Connection", "close")
            self.end_headers()
            self.wfile.write(payload)
            self.wfile.flush()
        except (BrokenPipeError, ConnectionResetError, ssl.SSLError):
            if parsed.path not in ("/timeout", "/cancel", "/redirect", "/oversize"):
                raise
        self.close_connection = True


def main():
    server = http.server.ThreadingHTTPServer(("93.184.216.34", 443), Provider)
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    context.set_alpn_protocols(["http/1.1"])
    context.load_cert_chain(sys.argv[1], sys.argv[2])
    server.socket = context.wrap_socket(server.socket, server_side=True)
    print("ready", flush=True)
    server.serve_forever()


if __name__ == "__main__":
    main()
