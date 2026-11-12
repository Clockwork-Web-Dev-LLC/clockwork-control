<?php

namespace Modules\Seo\Maps;

use RuntimeException;

/** An edit was based on a version of the row that someone has since changed. */
class StaleMapRowException extends RuntimeException {}
