<?php

// A Mode, but with a handler no adapter can run: an invokable object, not a Closure
return Swerve\AsyncMode::serve(null, new class {
    public function __invoke(Swerve\ClientRequest $request): void
    {
        $request->end();
    }
});
