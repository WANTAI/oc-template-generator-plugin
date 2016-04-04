<?php namespace LOVATA\TemplateGenerator;

use System\Classes\PluginBase;

class Plugin extends PluginBase
{

    /**
     * Returns information about this plugin.
     *
     * @return array
     */
    public function pluginDetails()
    {
        return [
            'name'        => 'Template Generator',
            'description' => 'lovata.templategenerator::lang.plugin.description',
            'author'      => 'LOVATA',
            'icon'        => 'oc-icon-skyatlas'
        ];
    }
    
    public function registerComponents()
    {
    }

    public function registerSettings()
    {
    }
}
