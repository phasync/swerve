// Node's http module, one process per worker (cluster): the file is streamed to the response
const cluster = require('node:cluster');
const http = require('node:http');
const fs = require('node:fs');

if (cluster.isPrimary) {
    for (let i = 0; i < +process.env.WORKERS; i++) cluster.fork();
} else {
    http.createServer((req, res) => {
        res.setHeader('Content-Type', 'text/plain');
        fs.createReadStream(process.env.FILE).pipe(res);
    }).listen(...(process.argv[2].startsWith('unix:') ? [process.argv[2].slice(5)] : [+process.argv[2].split(':')[1], process.argv[2].split(':')[0]]));
}
