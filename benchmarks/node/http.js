// node's built-in HTTP server, one process per worker (cluster)
const cluster = require('node:cluster');
const http = require('node:http');
const workers = +(process.env.WORKERS || 8);
const ports = process.argv[2].split(',').map(Number); // one or more ports
if (cluster.isPrimary) {
    for (let i = 0; i < workers; i++) cluster.fork();
} else {
    const handler = (req, res) => {
        res.writeHead(200, { 'Content-Type': 'text/plain' });
        res.end('Hello');
    };
    // One server per port: an http.Server can only listen once
    for (const port of ports) http.createServer(handler).listen(port, process.env.HOST || '127.0.0.1');
}
