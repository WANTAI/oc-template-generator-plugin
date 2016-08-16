<?php namespace Lovata\TemplateGenerator;

use System\Classes\PluginBase;

/**
 * Class Plugin
 * @package Lovata\TemplateGenerator
 * @author Andrey Kharanenka, a.khoronenko@lovata.com, LOVATA Group
 */
class Plugin extends PluginBase {
    
    public function registerComponents() {
        
    }

    public function registerSettings() {
        
    }

    public function boot()
    {
        $this->app->singleton('JtoT', function() {
            return new \Lovata\TemplateGenerator\Console\TemplateGenerator;
        });

        $this->commands('JtoT');
    }
}
