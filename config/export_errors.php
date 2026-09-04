<?php
/**
 * Turns a crashed export into a readable page instead of a blank HTTP 500.
 *
 * An export builds the whole workbook in memory and only sends the download
 * headers at the very end, so anything that throws on the way - a missing
 * table, a bad column, running out of memory - reaches the browser as an empty
 * "unable to handle this request". This catches it, writes the real reason to
 * the PHP error log and shows a one-line plain-text message with the file and
 * line, so the failure can be fixed without server log access.
 */
function export_report_errors(string $export_name): void
{
    static $installed = [];
    if (isset($installed[$export_name])) {
        return;
    }
    $installed[$export_name] = true;

    $emit = function (string $reason) use ($export_name) {
        error_log("Export failed ($export_name): $reason");
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            header_remove('Content-Disposition');
        }
        echo "The export could not be generated.\n\n$reason\n";
    };

    set_exception_handler(function (Throwable $e) use ($emit) {
        $emit(get_class($e) . ': ' . $e->getMessage()
            . ' in ' . $e->getFile() . ':' . $e->getLine());
    });

    register_shutdown_function(function () use ($emit) {
        $err = error_get_last();
        // Only fatals get reported here; warnings and notices are left alone
        if ($err && ($err['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
            $emit($err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        }
    });
}
