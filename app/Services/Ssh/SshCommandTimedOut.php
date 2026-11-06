<?php

namespace App\Services\Ssh;

use RuntimeException;

/**
 * A command run with SshClient::execChecked() didn't finish within its
 * timeout. phpseclib hands back whatever output arrived before the timeout
 * as if the command had finished, so a caller that needs the whole answer
 * (a malware check, say) gets this instead of a partial one.
 */
class SshCommandTimedOut extends RuntimeException {}
