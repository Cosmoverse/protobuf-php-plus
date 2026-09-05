<?php

declare(strict_types=1);

$project = getenv("PROTOP_TEST_PROJECT");
is_string($project) || throw new RuntimeException("PROTOP_TEST_PROJECT is not set");

require "{$project}/vendor/autoload.php";
require "{$project}/generated/GPBMetadata/Messages.php";
require "{$project}/generated/Protop/Test/Child.php";
require "{$project}/generated/Protop/Test/Sample.php";
