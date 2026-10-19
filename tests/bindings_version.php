<?php
namespace Encx {
    // Simulate an already loaded older binding, including a Composer setup.
    final class Client {}
    final class Helpers {}
}
namespace {
    require dirname(__DIR__).'/encounter.php';
    try {
        encxLoadBindings();
    } catch (\RuntimeException $e) {
        if (!str_contains($e->getMessage(), 'v0.26.0')) throw $e;
        echo "Old binding version rejected with upgrade instructions\n";
        exit;
    }
    throw new \RuntimeException('An already loaded older binding bypassed the minimum API check');
}
