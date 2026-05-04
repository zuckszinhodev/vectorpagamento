FROM node:20-alpine AS builder

WORKDIR /app

# Accept API URL as build arg
ARG VITE_API_URL=/api/v1
ENV VITE_API_URL=$VITE_API_URL

# Copy package files
COPY package.json package-lock.json* yarn.lock* pnpm-lock.yaml* ./

# Install dependencies
RUN npm ci --legacy-peer-deps 2>/dev/null || npm install --legacy-peer-deps

# Copy source code
COPY . .

# Build for production
RUN npm run build

# Production stage - serve with nginx
FROM nginx:alpine

# Copy built files
COPY --from=builder /app/dist /usr/share/nginx/html

# Copy nginx config for SPA
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf

# Run as non-root
RUN chown -R nginx:nginx /usr/share/nginx/html \
    && chown -R nginx:nginx /var/cache/nginx \
    && chown -R nginx:nginx /var/log/nginx \
    && touch /var/run/nginx.pid \
    && chown -R nginx:nginx /var/run/nginx.pid

USER nginx

EXPOSE 3000

CMD ["nginx", "-g", "daemon off;"]
