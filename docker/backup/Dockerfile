# Backup and restore tools (P1-51, PO decisions O47 and O48). Not a runtime service: run on demand (profile "tools").
# - mysqldump/mysql from the same pinned MySQL image as the database, so dump and server versions match.
# - AWS CLI v2 copied from the pinned aws-cli image (self-contained bundle) for the S3 version export.
# - age, built from its pinned module version; `go install` checks the module against the Go checksum database.
FROM golang:1.27.3-alpine3.24 AS age
RUN CGO_ENABLED=0 GOBIN=/out go install filippo.io/age/cmd/age@v1.3.2 filippo.io/age/cmd/age-keygen@v1.3.2

FROM amazon/aws-cli:2.39.3@sha256:d557b3524475d70dd3104cf44d96568ee3188897b73a6ece2b37aa50e1af362f AS awscli

FROM mysql:8.4.13
COPY --from=age /out/age /out/age-keygen /usr/local/bin/
COPY --from=awscli /usr/local/aws-cli /usr/local/aws-cli
RUN ln -s /usr/local/aws-cli/v2/current/bin/aws /usr/local/bin/aws \
 && aws --version && age --version && mysqldump --version
ENTRYPOINT []
CMD ["bash"]
