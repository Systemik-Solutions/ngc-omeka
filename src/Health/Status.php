<?php

namespace App\Health;

/**
 * The outcome of a single health check assertion.
 *
 * Only FAIL affects the exit code, and WARN only under --strict. INFO exists so that a check can
 * report a fact it has no opinion about - a deactivated module is a project decision, not a defect -
 * and SKIP so that an unconfigured probe is visibly distinct from a broken one.
 */
enum Status: string
{
    case PASS = 'PASS';
    case WARN = 'WARN';
    case FAIL = 'FAIL';
    case INFO = 'INFO';
    case SKIP = 'SKIP';
}
