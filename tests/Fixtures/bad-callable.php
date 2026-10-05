<?php

// RequestHandler takes a Closure, not any callable
return new Swerve\RequestHandler(new class {
    public function __invoke(Swerve\ClientRequest $request): void
    {
        $request->end();
    }
});
