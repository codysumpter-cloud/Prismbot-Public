FROM node:22-alpine

WORKDIR /app

# mission-control has no production deps today, but package metadata is kept for future growth
COPY package.json ./
COPY server.js smoke.test.js ./
COPY public ./public
COPY data ./data

ENV NODE_ENV=production
ENV HOST=0.0.0.0
ENV PORT=8790

EXPOSE 8790

CMD ["node", "server.js"]
