<?php

namespace Modules\Registry\Console;

use Illuminate\Console\Command;
use Modules\Registry\Support\CategoryTree;

/** Checks that every product category's path and depth agree with its parent's; exits 1 otherwise (TASK-0036 #1). */
class VerifyCategoryTree extends Command
{
    protected $signature = 'registry:verify-categories';

    protected $description = 'Verify that the product category tree is consistent';

    public function handle(): int
    {
        $inconsistent = CategoryTree::inconsistencies();
        if ($inconsistent === []) {
            $this->components->info('The product category tree is consistent.');

            return self::SUCCESS;
        }

        $this->components->error(count($inconsistent).' categor'.(count($inconsistent) === 1 ? 'y has' : 'ies have').' a path or depth that disagrees with the parent: '.implode(', ', $inconsistent));

        return self::FAILURE;
    }
}
