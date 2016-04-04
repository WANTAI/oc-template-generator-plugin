<?php namespace LOVATA\TemplateGenerator\Classes;

/**
 * Класс предназначен для преобразования HTML верстки в twig шаблоны
 * @author Andrey Kharanenka, a.khoronenko@LOVATA.com, LOVATA Group
 * Class HtmlToTwig
 * @package LOVATA\TemplateGenerator\Classes
 */
class HtmlToTwig {

    protected $sPathToHTML;
    protected $sBladeTemplateBasePath;

    protected $arTemplatesList = [
        [
            'name' => 'index',
            'file' => 'index.html',
//            'clear' => ['content'],
//            'extend' => [
//                ['name' => 'index', 'file' => 'index.html'],
//            ],
//        ],
//        ['name' => 'errors.500', 'file' => '500.html'],
//        ['name' => 'private_problem', 'file' => 'private-problem.html'],
        ],
        ['name' => 'login', 'file' => 'login.html']
    ];

    /** @var JadeToBlade */
    protected static $obThis;

    protected function __construct() {
        $this->sPathToHTML = public_path().'/html/';
        $this->sBladeTemplateBasePath = base_path().'/resources/views/';
    }

    /**
     * @return JadeToBlade
     */
    protected static function getInstance() {
        if(empty(self::$obThis)) {
            self::$obThis = new JadeToBlade();
        }

        return self::$obThis;
    }

    /**
     * Главный магичиский метод по преобразованию верстки в blade шаблоны
     */
    public static function run() {

        $obThis = self::getInstance();
        if(empty($obThis->arTemplatesList)) {
            return;
        }

        //Обработаем по порядку перечисленные html файлы
        foreach($obThis->arTemplatesList as $arTemplate) {
            if(!isset($arTemplate['name']) || empty($arTemplate['name'])) {
                continue;
            }

            $obThis->scanTemplate($arTemplate);
        }
    }

    /**
     * Сканируем шаблон для замены комментариев на blade конструкции
     * @param array $arTemplate
     * @param bool $bIsChild
     * @param array $arSectionsList
     * @param string $sParentTemplateName
     */
    protected function scanTemplate($arTemplate, $bIsChild = false, $arSectionsList = [], $sParentTemplateName = null) {

        if(!file_exists($this->sPathToHTML.$arTemplate['file'])) {
            return;
        }

        $sTemplateContent = file_get_contents($this->sPathToHTML.$arTemplate['file']);

        if($bIsChild) {
            $this->cutParentContent($sTemplateContent);
        }

        $this->cutContent($sTemplateContent);
        $this->cutForceContent($sTemplateContent);
        $this->replaceBladeSection($sTemplateContent);

        if($bIsChild) {
            $this->cutSections($sTemplateContent, $arSectionsList);
            $this->putExtendSection($sTemplateContent, $sParentTemplateName);
        }

        if(isset($arTemplate['clear']) && !empty($arTemplate['clear'])) {
            foreach($arTemplate['clear'] as $sSectionName) {
                $this->clearSections($sTemplateContent, $sSectionName);
            }
        }

        $this->replaceDataAttribute($sTemplateContent);
        $this->replaceIncludeSection($sTemplateContent);
        $arSections = $this->getSections($sTemplateContent);
        $this->filePutContent($arTemplate['name'], $sTemplateContent);

        if(isset($arTemplate['extend']) && !empty($arTemplate['extend'])) {
            foreach($arTemplate['extend'] as $arChildTemplate) {
                $this->scanTemplate($arChildTemplate, true, $arSections, $arTemplate['name']);
            }
        }
    }

    /**
     * Вставка кода из комментария
     * @param string $sTemplateContent
     */
    protected function replaceBladeSection(&$sTemplateContent) {

        //Начало вставляемой области
        $sSectionStart = '<!--bl:put';
        //Конец вставляемой области
        $sSectionStop = '/bl-->';

        //Получим позицию начала области
        $iSectionStart = strpos($sTemplateContent, $sSectionStart);

        //На данном этапе для избежания зацикливания поставим ограничение
        $i = 0;
        while($iSectionStart !== false && $i < 100) {

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
     * Вставка '@include'
     * @param string $sTemplateContent
     */
    protected function replaceIncludeSection(&$sTemplateContent) {

        $sSectionStart = '<!--bl:inc(';
        $sSectionStop = ')-->';
        $sIncludeSectionStop = '<!--bl:/inc-->';

        //Получим позицию начала тега
        $iSectionStart = strpos($sTemplateContent, $sSectionStart);

        //На данном этапе для избежания зацикливания поставим ограничение
        $i = 0;
        $bNeedRestart = false;
        while($iSectionStart !== false && $i < 100) {

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

            //Подставим секцию blade @include
            $sTemplateTmp = substr($sTemplateContent, 0, $iSectionStart);
            $sTemplateContent = $sTemplateTmp."\n"."@include('".$sSectionContent."')\n".substr($sTemplateContent, $iSectionStop + strlen($sSectionStop));
            unset($sTemplateTmp);

            //Вырезаем область вставки
            $sTemplateContent = str_replace($sIncludeContent.$sIncludeSectionStop, '' ,$sTemplateContent);

            //Сохраняем область вставки в файл
            $this->filePutContent($sSectionContent, $sIncludeContent);
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
        $sSectionStart = 'data-bl-';
        //Конец вставляемой области
        $sSectionStop = '"';

        //Получим позицию начала области
        $iSectionStart = strpos($sTemplateContent, $sSectionStart);

        //На данном этапе для избежания зацикливания поставим ограничение
        $i = 0;
        while($iSectionStart !== false && $i < 100) {

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
        $sSectionStart = '<!--bl:cut-->';
        //Конец вставляемой области
        $sSectionStop = '<!--bl:/cut-->';

        //Получим позицию начала области
        $iSectionStart = strpos($sTemplateContent, $sSectionStart);

        //На данном этапе для избежания зацикливания поставим ограничение
        $i = 0;
        $bNeedRestart = false;
        while($iSectionStart !== false && $i < 100) {

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
     * Вырезание контентных рыбных мест
     * @param $sTemplateContent
     */
    protected function cutForceContent(&$sTemplateContent) {

        //Начало вставляемой области
        $sSectionStart = '<!--bl:cut-force-->';
        //Конец вставляемой области
        $sSectionStop = '<!--bl:/cut-force-->';

        //Получим позицию начала области
        $iSectionStart = strpos($sTemplateContent, $sSectionStart);

        //На данном этапе для избежания зацикливания поставим ограничение
        $i = 0;
        $bNeedRestart = false;
        while($iSectionStart !== false && $i < 100) {

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
     * Вырезание лишних областей, которые присутствуют в родительском шаблоне
     * @param $sTemplateContent
     */
    protected function cutParentContent(&$sTemplateContent) {

        //Начало вставляемой области
        $sSectionStart = '<!--bl:cut-parent-->';
        //Конец вставляемой области
        $sSectionStop = '<!--bl:/cut-parent-->';

        //Получим позицию начала области
        $iSectionStart = strpos($sTemplateContent, $sSectionStart);

        //На данном этапе для избежания зацикливания поставим ограничение
        $i = 0;
        $bNeedRestart = false;
        while($iSectionStart !== false && $i < 100) {

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

    protected function getSections(&$sTemplateContent) {

        //Начало вставляемой области
        $sSectionStart = '@section(';
        //Конец вставляемой области
        $sSectionStop = ')';

        //Получим позицию начала области
        $iSectionStart = strpos($sTemplateContent, $sSectionStart);

        //На данном этапе для избежания зацикливания поставим ограничение
        $i = 0;
        $arResult = [];
        while($iSectionStart !== false && $i < 100) {

            //Получим позицию окончания области
            $iSectionStop = strpos($sTemplateContent, $sSectionStop, $iSectionStart);
            if($iSectionStop === false || $iSectionStart >= $iSectionStop) {
                break;
            }

            //Получим контент вставляемой области
            $arResult[] = substr($sTemplateContent, $iSectionStart + strlen($sSectionStart), $iSectionStop - strlen($sSectionStart) - $iSectionStart);

            //Получим позицию начала области
            $iSectionStart = strpos($sTemplateContent, $sSectionStart, $iSectionStart + 1);
            $i++;
        }

        return $arResult;
    }

    /**
     * Вырезаем области дочерних шаблонов, которые уже определены в родительском шаблоне
     * @param $sTemplateContent
     * @param $arSectionList
     */
    protected function cutSections(&$sTemplateContent, $arSectionList) {

        //Начало вставляемой области
        $sSectionStart = '@section(';
        //Конец вставляемой области
        $sSectionStop = ')';

        //Получим позицию начала области
        $iSectionStart = strpos($sTemplateContent, $sSectionStart);

        //На данном этапе для избежания зацикливания поставим ограничение
        $i = 0;
        while($iSectionStart !== false && $i < 100) {

            //Получим позицию окончания области
            $iSectionStop = strpos($sTemplateContent, $sSectionStop, $iSectionStart);
            if($iSectionStop === false || $iSectionStart >= $iSectionStop) {
                break;
            }

            //Получим контент вставляемой области
            $sSectionName = substr($sTemplateContent, $iSectionStart + strlen($sSectionStart), $iSectionStop - strlen($sSectionStart) - $iSectionStart);

            if(in_array($sSectionName, $arSectionList)) {
                $iPosOverwrite = strpos($sTemplateContent, '@overwrite', $iSectionStop);
                $iPosShow = strpos($sTemplateContent, '@show', $iSectionStop);

                if($iPosShow !== false && (($iPosOverwrite !== false && $iPosShow < $iPosOverwrite) || $iPosOverwrite === false)) {
                    //Заменим закомментированную область на вставляемое содержимое

                    $sTemplateTmp = substr($sTemplateContent, 0, $iSectionStart);
                    $sTemplateContent = $sTemplateTmp."\n".substr($sTemplateContent, $iPosShow + 5);
                    unset($sTemplateTmp);
                }
            }

            //Получим позицию начала области
            $iSectionStart = strpos($sTemplateContent, $sSectionStart, $iSectionStart + 1);
            $i++;
        }
    }

    /**
     * Очистка содержимого @section
     * @param $sTemplateContent
     * @param $sSectionClearName
     */
    protected function clearSections(&$sTemplateContent, $sSectionClearName) {

        //Начало вставляемой области
        $sSectionStart = '@section(';
        //Конец вставляемой области
        $sSectionStop = ')';

        //Получим позицию начала области
        $iSectionStart = strpos($sTemplateContent, $sSectionStart);

        //На данном этапе для избежания зацикливания поставим ограничение
        $i = 0;
        while($iSectionStart !== false && $i < 100) {

            //Получим позицию окончания области
            $iSectionStop = strpos($sTemplateContent, $sSectionStop, $iSectionStart);
            if($iSectionStop === false || $iSectionStart >= $iSectionStop) {
                break;
            }

            //Получим контент вставляемой области
            $sSectionName = substr($sTemplateContent, $iSectionStart + strlen($sSectionStart), $iSectionStop - strlen($sSectionStart) - $iSectionStart);

            $sSectionName = trim($sSectionName, '"');
            $sSectionName = trim($sSectionName, "'");
            if($sSectionName == $sSectionClearName) {
                $iPosShow = strpos($sTemplateContent, '@show', $iSectionStop);

                if($iPosShow !== false) {

                    $sTemplateTmp = substr($sTemplateContent, 0, $iSectionStop + strlen($sSectionStop));
                    $sTemplateContent = $sTemplateTmp."\n".substr($sTemplateContent, $iPosShow);
                    unset($sTemplateTmp);
                }
            }

            //Получим позицию начала области
            $iSectionStart = strpos($sTemplateContent, $sSectionStart, $iSectionStart + 1);
            $i++;
        }
    }

    /**
     * Вставка тега @extends
     * @param $sTemplateContent
     * @param $sParentTemplateName
     */
    protected function putExtendSection(&$sTemplateContent, $sParentTemplateName) {
        $sTemplateContent = "@extends('".$sParentTemplateName."')".$sTemplateContent;
    }

    /**
     * Запись содержимого в файл
     * @param string $sTemplateName
     * @param string $sContent
     */
    protected function filePutContent($sTemplateName, &$sContent) {

        $sTemplatePath = $this->sBladeTemplateBasePath;
        $arTemplatePath = explode('.', $sTemplateName);
        while($sTemplateName = array_shift($arTemplatePath)) {
            if(empty($arTemplatePath)) {
                break;
            }

            $sTemplatePath .= $sTemplateName.'/';
            if(!file_exists($sTemplatePath)) {
                mkdir($sTemplatePath);
            }
        }

        file_put_contents($sTemplatePath.$sTemplateName.'.blade.php', $sContent);
    }
}