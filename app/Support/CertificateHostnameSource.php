<?php

namespace App\Support;

/**
 * Something that has the edge answer on a hostname of its own, one no domain has, like the host a CDN
 * pulls files through. The edge may get a certificate for such a hostname (/caddy/ask); what it does
 * with requests for it is up to the add-on's edge handler. Sources are tagged in the container under
 * CertificateHostnames::TAG.
 */
interface CertificateHostnameSource
{
    /**
     * Determine if the edge may get a certificate for the hostname, which comes valid and in lower case.
     */
    public function vouchesFor(string $hostname): bool;
}
