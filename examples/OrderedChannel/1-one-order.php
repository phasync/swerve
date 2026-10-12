<?php

use Swerve\OrderedChannel;

phasync::run(function () {
    $ledger = new OrderedChannel('ledger');

    // Two subscribers, which in a server would be in different workers
    foreach (['audit', 'report'] as $name) {
        $subscription = $ledger->subscribe();
        phasync::go(function () use ($subscription, $name) {
            $entries = [];
            foreach ($subscription as $entry) {
                $entries[] = implode(' ', $entry);
                if (3 === count($entries)) {
                    break;
                }
            }
            echo "$name: ", implode(', ', $entries), "\n";   // the same order for both
        });
    }

    // Several writers; every subscriber sees one common order
    phasync::go(fn () => $ledger->write(['debit', 12]));
    phasync::go(fn () => $ledger->write(['credit', 5]));
    phasync::go(fn () => $ledger->write(['debit', 3]));
});
