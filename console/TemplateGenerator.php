<?php namespace LOVATA\TemplateGenerator\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

class TemplateGenerator extends Command {
    
    /**
     * @var string The console command name.
     */
    protected $name = 'HtoT';

    /**
     * @var string The console command description.
     */
    protected $description = 'Generate twig templates from html files';

    /**
     * Execute the console command.
     * @return void
     */
    public function fire() {
        
    }

    /**
     * Get the console command arguments.
     * @return array
     */
    protected function getArguments() {
        return [];
    }

    /**
     * Get the console command options.
     * @return array
     */
    protected function getOptions() {
        return [];
    }

}