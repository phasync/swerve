<?php

// The realistic page, shared by every server: about 10 KB of HTML from a template with a loop.
// $rows stands in for a query result the application keeps (built once per worker).

function page_rows(): array
{
    static $rows;
    if (null === $rows) {
        $statuses = ['paid', 'pending', 'shipped', 'refunded'];
        for ($i = 1; $i <= 50; ++$i) {
            $rows[] = [
                'id' => $i,
                'name' => "Customer <$i> & Sons",
                'email' => "customer$i@example.com",
                'total' => $i * 37.5 + $i / 7,
                'status' => $statuses[$i % 4],
            ];
        }
    }

    return $rows;
}

function render_page(): string
{
    $title = 'Orders & invoices';
    $rows = page_rows();
    \ob_start();
    include __DIR__ . '/page.tpl.php';

    return \ob_get_clean();
}
