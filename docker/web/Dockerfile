FROM nginx:1.27-alpine

# Self-signed certificate for local HTTPS / HTTP/2. Baked into the image so the
# stack stays self-contained (no host artifacts to generate or mount).
RUN apk add --no-cache openssl \
 && mkdir -p /etc/nginx/certs \
 && openssl req -x509 -nodes -newkey rsa:2048 -days 825 \
      -keyout /etc/nginx/certs/self.key \
      -out   /etc/nginx/certs/self.crt \
      -subj "/CN=localhost" \
      -addext "subjectAltName=DNS:localhost,DNS:paybis-web,IP:127.0.0.1"
