<?php
// A symfony/runtime Runtime, as little as autoload_runtime.php uses: the app's argument is an option
final class RuntimeFixture
{
    public function __construct(private readonly array $options)
    {
    }

    public function getResolver(callable $app): object
    {
        $options = $this->options;

        return new class($app, $options) {
            public function __construct(private readonly mixed $app, private readonly array $options)
            {
            }

            public function resolve(): array
            {
                return [$this->app, [$this->options['greeting'] . ' from ' . \basename($this->options['project_dir'])]];
            }
        };
    }

    public function getRunner(string $app): object
    {
        return new class($app) {
            public function __construct(private readonly string $app)
            {
            }

            public function run(): int
            {
                echo $this->app;

                return 0;
            }
        };
    }
}
