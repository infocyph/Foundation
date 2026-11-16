#!/usr/bin/env python3
"""Real HTTP/2 gRPC loopback fixture; only test data, no app transport logic."""
import json
import sys
from concurrent import futures

import grpc

ready = sys.argv[1]

def echo(data: bytes, context: grpc.ServicerContext) -> bytes:
    context.set_trailing_metadata((("x-source", "native-grpc"),))
    return b"echo:" + data

service = grpc.server(futures.ThreadPoolExecutor(max_workers=2))
service.add_generic_rpc_handlers((
    grpc.method_handlers_generic_handler(
        "foundation.Integration",
        {
            "Unary": grpc.unary_unary_rpc_method_handler(
                echo,
                request_deserializer=lambda b: b,
                response_serializer=lambda b: b,
            ),
        },
    ),
))
port = service.add_insecure_port("127.0.0.1:0")
if port <= 0:
    raise RuntimeError("Unable to bind native gRPC loopback listener.")
service.start()
with open(ready, "w", encoding="utf-8") as handle:
    json.dump({"port": port}, handle)
try:
    service.wait_for_termination()
except KeyboardInterrupt:
    service.stop(grace=0)
