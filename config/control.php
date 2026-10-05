<?php

return [
    /*
     * An operation (deployment, backup, restart) that has produced no terminal
     * state within this many seconds is treated as abandoned and released.
     * It must stay comfortably above the longest queue job timeout (1800s) so a
     * slow but healthy deployment is never reaped. 2700s = 45 minutes.
     */
    "stale_operation_after" => (int) env("CONTROL_STALE_OPERATION_AFTER", 2700),

    /*
     * Test-only escape hatch. When false (always in production) remote
     * destinations must resolve to public IP addresses, which prevents the
     * control plane from being used to probe or reach internal networks.
     * The integration suite enables it to exercise a real local SSH server.
     */
    "allow_private_targets" => (bool) env("CONTROL_ALLOW_PRIVATE_TARGETS", false),
];
