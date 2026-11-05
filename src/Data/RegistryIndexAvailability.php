<?php

namespace Splicewire\Beam\Docs\Data;

/**
 * Where one catalog root stands on this host, as the registry index draws it. The same answers the
 * per-link nav spells into a title ("conduit (not available on this host)"), kept as a value so a
 * renderer can style the state rather than parse it out of a string.
 */
enum RegistryIndexAvailability: string
{
    /** This host serves the root's page. */
    case Served = 'served';
    /** The catalog points at a better page elsewhere. */
    case External = 'external';
    /** The owning package is not installed here, or this host's index does not describe the root. */
    case NotAvailable = 'not-available';
    /** The package is here and describes the root, but this host does not publish its page. */
    case NotPublished = 'not-published';
    /** The catalog lists the root with no page yet. */
    case NotDocumented = 'not-documented';
    /** The root is exempt from a page (its class is in the note). */
    case Exempt = 'exempt';
}
