<?php namespace Lovata\TemplateGenerator\Classes;

/**
 * Класс предназначен для преобразования HTML верстки в twig шаблоны
 * @author Andrey Kharanenka, a.khoronenko@lovata.com, LOVATA Group
 * Class HtmlToTwig
 * @package Lovata\TemplateGenerator\Classes
 */
class HtmlToTwig {

    protected $sThemeName = null;
    protected $sPathToThemes = null;
    protected $arThemeList = [];
    
    protected $arTemplateSettings = [];

    /** @var HtmlToTwig */
    protected static $obThis;

    protected function __construct() {
        
        $this->sPathToThemes = public_path().'/themes/';
        
        //Получим список тем, которые необходимо обработать
        $arFileList  = scandir($this->sPathToThemes);
        if(empty($arFileList)) {
            return;
        }

        foreach ($arFileList as $sFileName) {
            if($sFileName == '.' || $sFileName == '..') {
                continue;
            }
            
            //Если в папке темы есть папка html, то добавляем тему в спиок обработки
            if(is_dir($this->sPathToThemes.$sFileName) && file_exists($this->sPathToThemes.$sFileName.'/html')) {
                $this->arThemeList[] = $sFileName;
            }
        }
    }

    /**
     * @return HtmlToTwig
     */
    protected static function getInstance() {
        if(empty(self::$obThis)) {
            self::$obThis = new HtmlToTwig();
        }

        return self::$obThis;
    }

    /**
     * Главный магический метод по преобразованию верстки в blade шаблоны
     */
    public static function run() {

        $obThis = self::getInstance();
        if(empty($obThis->arThemeList)) {
            return;
        }
        
        //Обработаем темы
        foreach ($obThis->arThemeList as $sThemeName) {

            $sPathToHTML = $obThis->sPathToThemes.$sThemeName.'/html/';
            
            $obThis->sThemeName = $sThemeName;
            $obThis->scanFolder($sPathToHTML);
        }
    }

    /**
     * Сканирование папки с темой
     * @param string $sPathToHTML
     */
    protected function scanFolder($sPathToHTML) {

        //Обработаем по порядку html файлы
        $arFileList = scandir($sPathToHTML);
        if(empty($arFileList)) {
            return;
        }

        foreach ($arFileList as $sFileName) {
            if($sFileName == '.' || $sFileName == '..') {
                continue;
            }
            
            if(is_dir($sPathToHTML.$sFileName)) {
                $this->scanFolder($sPathToHTML.$sFileName.'/');
                continue;
            }
            
            $this->scanTemplate($sPathToHTML.$sFileName);
        }
    }

    /**
     * Сканируем шаблон для замены комментариев на twig конструкции
     * @param string $sFilePath
     */
    protected function scanTemplate($sFilePath) {

        if(!file_exists($sFilePath)) {
            return;
        }

        $sTemplateContent = file_get_contents($sFilePath);
        
        //Получим настройки шаблона
        $this->getTemplateSettings($sTemplateContent);
        
        //Проверим полученные настройки
        if(empty($this->arTemplateSettings) || !isset($this->arTemplateSettings['name']) || !isset($this->arTemplateSettings['folder'])) {
            return;
        }

        //Вырезаем лишние области
        $this->cutContent($sTemplateContent);
        
        //Вставляем секции Twig
        $this->replaceSection($sTemplateContent);

        //Заменяем data аттрибуты
        $this->replaceDataAttribute($sTemplateContent);
        
        //Вырезаем области вставки
        $this->replaceIncludeSection($sTemplateContent);

        $this->filePutContent($sTemplateContent, $this->arTemplateSettings);
    }
    
    protected function getTemplateSettings(&$sTemplateContent) {

        $this->arTemplateSettings = [];
        
        //Начало вставляемой области
        $sSectionStart = '<!--oc:settings:';
        //Конец вставляемой области
        $sSectionStop = '/oc-->';

        //Получим позицию начала области
        $iSectionStart = strpos($sTemplateContent, $sSectionStart);

        //Получим позицию окончания области
        $iSectionStop = strpos($sTemplateContent, $sSectionStop, $iSectionStart);
        if($iSectionStop === false || $iSectionStart >= $iSectionStop) {
            return;
        }

        //Получим контент вставляемой области
        $sSectionContent = substr($sTemplateContent, $iSectionStart + strlen($sSectionStart), $iSectionStop - strlen($sSectionStart) - $iSectionStart);

        $sSectionContent = str_replace('&amp;', '&', $sSectionContent);
        $sSectionContent = str_replace('&gt;', '>', $sSectionContent);
        $sSectionContent = str_replace('&lt;', '<', $sSectionContent);
        
        $sSectionContent = trim($sSectionContent);

        //Заменим закомментированную область на вставляемое содержимое
        $sTemplateTmp = substr($sTemplateContent, 0, $iSectionStart);
        $sTemplateContent = $sTemplateTmp.substr($sTemplateContent, $iSectionStop + strlen($sSectionStop));
        unset($sTemplateTmp);

        $this->arTemplateSettings = $this->parseSettings($sSectionContent);
    }

    /**
     * Получение конфига блока
     * @param $sSectionContent
     * @return array|null
     */
    protected function parseSettings($sSectionContent) {
        
        $arResult = null;
        
        //Получим массив настроек
        $arSettingsVariants = explode('|', trim($sSectionContent));
        if(empty($arSettingsVariants)) {
            return $arResult;
        }

        foreach ($arSettingsVariants as $sSettingsVariant) {
            $arSettings = explode('=', trim($sSettingsVariant));

            $sSettingsKey = trim(array_shift($arSettings));
            $sSettingsValue = trim(array_shift($arSettings));
            if(empty($sSettingsKey) || empty($sSettingsValue)) {
                continue;
            }

            $arResult[$sSettingsKey] = $sSettingsValue;
        }
        
        return $arResult;
    }

    /**
     * Вставка кода из комментария
     * @param string $sTemplateContent
     */
    protected function replaceSection(&$sTemplateContent) {

        //Начало вставляемой области
        $sSectionStart = '<!--oc:put';
        //Конец вставляемой области
        $sSectionStop = '/oc-->';

        //Получим позицию начала области
        $iSectionStart = strpos($sTemplateContent, $sSectionStart);

        //На данном этапе для избежания зацикливания поставим ограничение
        $i = 0;
        while($iSectionStart !== false && $i < 10000) {

            //Получим позицию окончания области
            $iSectionStop = strpos($sTemplateContent, $sSectionStop, $iSectionStart);
            if($iSectionStop === false || $iSectionStart >= $iSectionStop) {
                break;
            }

            //Получим контент вставляемой области
            $sSectionContent = substr($sTemplateContent, $iSectionStart + strlen($sSectionStart), $iSectionStop - strlen($sSectionStart) - $iSectionStart);

            $sSectionContent = str_replace('&amp;', '&', $sSectionContent);
            $sSectionContent = str_replace('&gt;', '>', $sSectionContent);
            $sSectionContent = str_replace('&lt;', '<', $sSectionContent);

            //Заменим закомментированную область на вставляемое содержимое
            $sTemplateTmp = substr($sTemplateContent, 0, $iSectionStart);
            $sTemplateContent = $sTemplateTmp."\n".trim($sSectionContent)."\n".substr($sTemplateContent, $iSectionStop + strlen($sSectionStop));
            unset($sTemplateTmp);

            //Получим позицию начала области
            $iSectionStart = strpos($sTemplateContent, $sSectionStart, $iSectionStart);
            $i++;
        }
    }

    /**
     * Вырезание и вставка области
     * @param string $sTemplateContent
     */
    protected function replaceIncludeSection(&$sTemplateContent) {

        $sSectionStart = '<!--oc:inc:';
        $sSectionStop = '/oc-->';
        $sIncludeSectionStop = '<!--oc:/inc-->';

        //Получим позицию начала тега
        $iSectionStart = strpos($sTemplateContent, $sSectionStart);

        //На данном этапе для избежания зацикливания поставим ограничение
        $i = 0;
        $bNeedRestart = false;
        while($iSectionStart !== false && $i < 10000) {

            //Получим позицию окончания тега
            $iSectionStop = strpos($sTemplateContent, $sSectionStop, $iSectionStart);
            if($iSectionStop === false || $iSectionStart >= $iSectionStop) {
                break;
            }
            
            //Получим позицию окончания области
            $iIncludeSectionStop = strpos($sTemplateContent, $sIncludeSectionStop, $iSectionStop);
            if($iIncludeSectionStop === false) {
                break;
            }
            
            //Получим позицию начала тега (для проверки вложенности)
            $iSectionStartTmp = strpos($sTemplateContent, $sSectionStart, $iSectionStart + 1);
            if($iSectionStartTmp !== false && $iSectionStartTmp < $iIncludeSectionStop) {
                $iSectionStart = $iSectionStartTmp;
                $bNeedRestart = true;
                $i++;
                continue;
            }

            //Получим контент вставляемой области
            $sIncludeContent = substr($sTemplateContent, $iSectionStop + strlen($sSectionStop), $iIncludeSectionStop - strlen($sSectionStop) - $iSectionStop);

            //Получим название шаблона вставляемой области
            $sSectionContent = substr($sTemplateContent, $iSectionStart + strlen($sSectionStart), $iSectionStop - strlen($sSectionStart) - $iSectionStart);

            //Вырезаем область вставки
            $sTemplateTmp = substr($sTemplateContent, 0, $iSectionStart);
            $sTemplateContent = $sTemplateTmp."\n".substr($sTemplateContent, $iIncludeSectionStop + strlen($sIncludeSectionStop));
            unset($sTemplateTmp);

            //Получим настройки вставляемой области
            $arSettings = $this->parseSettings($sSectionContent);
            
            //Сохраняем область вставки в файл
            $this->filePutContent($sIncludeContent, $arSettings);
            unset($sIncludeContent);

            //Получим позицию начала тега
            if($bNeedRestart) {
                $bNeedRestart = false;
                $iSectionStart = strpos($sTemplateContent, $sSectionStart);
            } else {
                $iSectionStart = strpos($sTemplateContent, $sSectionStart, $iSectionStart);
            }
            $i++;
        }
    }

    /**
     * Замена аттрибутов необходимыми значениями
     * @param $sTemplateContent
     */
    protected function replaceDataAttribute(&$sTemplateContent) {

        //Начало вставляемой области
        $sSectionStart = 'data-oc-';
        //Конец вставляемой области
        $sSectionStop = '"';

        //Получим позицию начала области
        $iSectionStart = strpos($sTemplateContent, $sSectionStart);

        //На данном этапе для избежания зацикливания поставим ограничение
        $i = 0;
        while($iSectionStart !== false && $i < 10000) {

            //Получим позицию окончания области
            //Два раза для поиска закрывающей ковычки
            $iSectionStop = strpos($sTemplateContent, $sSectionStop, $iSectionStart);
            $iSectionStop = strpos($sTemplateContent, $sSectionStop, $iSectionStop + 1);
            if($iSectionStop === false || $iSectionStart >= $iSectionStop) {
                break;
            }
            
            $sContentStartPart = substr($sTemplateContent, 0, $iSectionStart);
            $sContentStopPart = substr($sTemplateContent, $iSectionStop + strlen($sSectionStop));

            //Получим контент вставляемой области
            $sSectionContent = substr($sTemplateContent, $iSectionStart + strlen($sSectionStart), $iSectionStop - strlen($sSectionStart) - $iSectionStart);

            $sSectionContent = str_replace('&amp;', '&', $sSectionContent);
            $sSectionContent = str_replace('&gt;', '>', $sSectionContent);
            $sSectionContent = str_replace('&lt;', '<', $sSectionContent);

            //Получит название аттрибута
            $arAttrName = explode('=', $sSectionContent);
            $sAttrName = array_shift($arAttrName);

            //Получим позиции заменяемого аттрибута
            $iAttrStart = strpos($sContentStopPart, $sAttrName.'="');
            $iAttrStop = strpos($sContentStopPart, '"', $iAttrStart + strlen($sAttrName) + 2);

            $sContentStartPart .= substr($sContentStopPart, 0, $iAttrStart);
            $sContentStopPart = substr($sContentStopPart, $iAttrStop);

            $sTemplateContent = $sContentStartPart.$sSectionContent.$sContentStopPart;
            unset($sContentStartPart);
            unset($sContentStopPart);

            //Получим позицию начала области
            $iSectionStart = strpos($sTemplateContent, $sSectionStart);
            $i++;
        }
    }

    /**
     * Вырезание контентных рыбных мест
     * @param $sTemplateContent
     */
    protected function cutContent(&$sTemplateContent) {

        //Начало вставляемой области
        $sSectionStart = '<!--oc:cut-->';
        //Конец вставляемой области
        $sSectionStop = '<!--oc:/cut-->';

        //Получим позицию начала области
        $iSectionStart = strpos($sTemplateContent, $sSectionStart);

        //На данном этапе для избежания зацикливания поставим ограничение
        $i = 0;
        $bNeedRestart = false;
        while($iSectionStart !== false && $i < 10000) {

            //Получим позицию окончания области
            $iSectionStop = strpos($sTemplateContent, $sSectionStop, $iSectionStart);
            if($iSectionStop === false || $iSectionStart >= $iSectionStop) {
                break;
            }

            //Получим позицию начала тега (для проверки вложенности)
            $iSectionStartTmp = strpos($sTemplateContent, $sSectionStart, $iSectionStart + 1);
            if($iSectionStartTmp !== false && $iSectionStartTmp < $iSectionStop) {
                $iSectionStart = $iSectionStartTmp;
                $bNeedRestart = true;
                $i++;
                continue;
            }

            //Заменим закомментированную область на вставляемое содержимое
            $sTemplateTmp = substr($sTemplateContent, 0, $iSectionStart);
            $sTemplateContent = $sTemplateTmp."\n".substr($sTemplateContent, $iSectionStop + strlen($sSectionStop));
            unset($sTemplateTmp);

            //Получим позицию начала области
            //Получим позицию начала тега
            if($bNeedRestart) {
                $bNeedRestart = false;
                $iSectionStart = strpos($sTemplateContent, $sSectionStart);
            } else {
                $iSectionStart = strpos($sTemplateContent, $sSectionStart, $iSectionStart);
            }
            $i++;
        }
    }

    /**
     * Запись содержимого в файл
     * @param string $sTemplateContent
     * @param array $arTemplateSettings
     */
    protected function filePutContent(&$sTemplateContent, $arTemplateSettings) {
        
        if(empty($arTemplateSettings) || !isset($arTemplateSettings['folder']) || !isset($arTemplateSettings['name'])) {
            unset($sTemplateContent);
            return;
        }
        
        //Пулучим путь к шаблону и создадим вложенные папки
        $sTemplatePath = $this->sPathToThemes.$this->sThemeName.'/';
        
        $arFolderPath = explode('.', $arTemplateSettings['folder']);
        while(!empty($sFolderName = array_shift($arFolderPath))) {

            $sTemplatePath .= $sFolderName;
            if(!file_exists($sTemplatePath)) {
                mkdir($sTemplatePath);
            }

            $sTemplatePath .= '/';
        }

        //Начало вставляемой области
        $sSectionStart = '{#oc:start#}';
        $sTemplateContent = $sSectionStart.$sTemplateContent;
        
        //Получим полный путь к файлу
        $sFilePath = $sTemplatePath.$arTemplateSettings['name'].'.htm';

        //Проверим если файл не новый, то получаем конфиг OctoberCMS и после него вставляем контент страницы
        if(file_exists($sFilePath)) {

            $sOldTemplateContent = file_get_contents($sFilePath);
            
            //Получим позицию начала области
            $iSectionStart = strpos($sOldTemplateContent, $sSectionStart);
            if($iSectionStart !== false && $iSectionStart > 0) {
                $sTemplateContent = substr($sOldTemplateContent, 0, $iSectionStart).$sTemplateContent;
            }
        
        }

        file_put_contents($sFilePath, $sTemplateContent);
        unset($sTemplateContent);
    }
}