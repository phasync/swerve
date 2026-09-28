// Express, one process per worker (cluster)
const cluster = require('node:cluster');
const workers = +(process.env.WORKERS || 8);
const ports = process.argv[2].split(',').map(Number); // one or more ports
if (cluster.isPrimary) {
    for (let i = 0; i < workers; i++) cluster.fork();
} else {
    const app = require('express')();
    app.get('/', (req, res) => res.send('Hello, World'));
    for (const port of ports) app.listen(port, process.env.HOST || '127.0.0.1');
}
