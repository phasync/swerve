<?php
while (Swerve\Swerve::accept(null, function (Swerve\ClientRequest $client) {
    $client->sendResponseHeaders(200, ['content-type' => 'text/plain']);
    $client->write('client ' . $client->getTarget());
    $client->end();
})) {
}
