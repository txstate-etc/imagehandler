FROM alpine:3.4

ENTRYPOINT ["find", "/cache", "-name", "data", "-delete"]
CMD ["-mtime", "+60"]
