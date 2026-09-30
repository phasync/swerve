// Node's http module, one process per worker (cluster)
const cluster = require('node:cluster');
const http = require('node:http');

if (cluster.isPrimary) {
    for (let i = 0; i < +process.env.WORKERS; i++) cluster.fork();
} else {
    http.createServer((req, res) => {
        res.writeHead(200, { 'Content-Type': 'text/plain' });
        res.end('Hello, World!');
    }).listen(+process.argv[2], '127.0.0.1');
}
