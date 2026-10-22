<?php

// A static property and a function-local static: both must reset to their initial value every
// request, even when the request server's child process is reused across requests.
class CounterFixtureState
{
    public static int $n = 0;
}

function counter_fixture_next(): int
{
    static $n = 0;

    return ++$n;
}

echo json_encode(['static_prop' => ++CounterFixtureState::$n, 'static_local' => counter_fixture_next()]);
