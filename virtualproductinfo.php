<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class VirtualProductInfo extends Module
{
    protected $config_form = false;

    public function __construct()
    {
        $this->name = 'virtualproductinfo';
        $this->tab = 'front_office_features';
        $this->version = '1.1.3';
        $this->author = 'artem78';
        $this->need_instance = 0;
        $this->bootstrap = true;
        
        parent::__construct();
        
        $this->displayName = $this->l('Virtual Product Info');
        $this->description = $this->l('Show details of virtual product on product page');
        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
    }

    public function install()
    {
        $success = parent::install() && $this->registerHook('displayProductAdditionalInfo');
        
        if ($success && !$this->getInstallTimestamp()) {
            $success = $success && $this->setInstallTimestamp();
        }
        
        return $success;
    }

    public function uninstall()
    {
        return parent::uninstall();
    }

    private static function humanFileSize($bytes, $decimals = 2)
    {
        $size = array('K', 'M', 'G', 'T', 'P');
        $factor = floor((strlen($bytes) - 1) / 3);
        
        if ($factor > 0) {
            $bytes = sprintf("%.{$decimals}f", $bytes / pow(1024, $factor));
            $unit = @$size[$factor - 1] . 'B';
        } else {
            $bytes = $bytes;
            $unit = 'bytes';
        }
        
        return $bytes . ' ' . $unit;
    }

    private static function getAdditionalFileInfo($filePath, $fileExtension = null)
    {
        $info = array();
        
        if (!$fileExtension) {
            $fileExtension = pathinfo($filePath, PATHINFO_EXTENSION);
        }
        
        switch (strtolower($fileExtension)) {
            case 'bmp':
            case 'png':
            case 'jpg':
            case 'jpeg':
            case 'gif':
                $imageSize = getimagesize($filePath);
                if ($imageSize) {
                    $info['dimension'] = sprintf('%d x %d px', $imageSize[0], $imageSize[1]);
                }
                break;
                
            case 'txt':
                $lineCount = 0;
                $wordCount = 0;
                $file = fopen($filePath, 'r');
                
                while (!feof($file)) {
                    $line = fgets($file);
                    $lineCount++;
                    $wordCount += str_word_count($line);
                }
                
                fclose($file);
                $info['line_count'] = $lineCount . ' lines';
                $info['word_count'] = $wordCount . ' words';
                break;
        }
        
        return $info;
    }

    private static function getZipContentsInfo($zipPath)
    {
        $zip = new ZipArchive();
        
        if ($zip->open($zipPath) !== TRUE) {
            return FALSE;
        }
        
        $filesInfo = array();
        $tempDir = VirtualProductInfo::tempdir('ps_zip_prw_');
        $zip->extractTo($tempDir);
        
        for ($i = 0; $filename = $zip->getNameIndex($i); $i++) {
            $filePath = $tempDir . '/' . $filename;
            
            if (is_file($filePath)) {
                $filesInfo[] = array(
                    'filename' => $filename,
                    'size' => VirtualProductInfo::humanFileSize($zip->statIndex($i)['size']),
                    'compressed_size' => VirtualProductInfo::humanFileSize($zip->statIndex($i)['comp_size']),
                    'type' => strtoupper(pathinfo($filename, PATHINFO_EXTENSION)),
                    'additional_info' => VirtualProductInfo::getAdditionalFileInfo($filePath, pathinfo($filename, PATHINFO_EXTENSION))
                );
                unlink($filePath);
            }
        }
        
        $zip->close();
        VirtualProductInfo::recurseRmdir($tempDir);
        
        return $filesInfo;
    }

    private static function tempdir($prefix = '')
    {
        $tempFile = tempnam(sys_get_temp_dir(), $prefix);
        
        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
        
        mkdir($tempFile);
        
        if (is_dir($tempFile)) {
            return $tempFile;
        }
    }

    private static function recurseRmdir($dir)
    {
        $files = array_diff(scandir($dir), array('.', '..'));
        
        foreach ($files as $file) {
            is_dir("{$dir}/{$file}") && !is_link("{$dir}/{$file}") ? 
                VirtualProductInfo::recurseRmdir("{$dir}/{$file}") : 
                unlink("{$dir}/{$file}");
        }
        
        return rmdir($dir);
    }

    public function hookDisplayProductAdditionalInfo($params)
    {
        if (!$this->isModuleActivated() && !$this->isTrialNotExpired()) {
            return '<b><!-- Trial expired. You should activate VirtualProductInfo module!</b>';
        }
        
        $query = new DbQuery();
        $query->select('*');
        $query->from('product_download');
        $query->where('id_product = ' . $params['product']['id_product']);
        
        $downloadData = Db::getInstance()->executeS($query);
        $files = array();
        
        if (!$downloadData) {
            return;
        }
        
        if (str_ends_with(strtolower($downloadData[0]['display_filename']), '.zip')) {
            $files[] = array(
                'filename' => $downloadData[0]['display_filename'],
                'size' => VirtualProductInfo::humanFileSize(filesize('download/' . $downloadData[0]['filename'])),
                'type' => strtoupper(pathinfo($downloadData[0]['display_filename'], PATHINFO_EXTENSION))
            );
            
            $zipContents = VirtualProductInfo::getZipContentsInfo('download/' . $downloadData[0]['filename']);
            
            if ($zipContents === FALSE) {
                // Handle error
            } else {
                $files = array_merge($files, $zipContents);
            }
        } else {
            $files[] = array(
                'filename' => $downloadData[0]['display_filename'],
                'size' => VirtualProductInfo::humanFileSize(filesize('download/' . $downloadData[0]['filename'])),
                'type' => $info['type'] = strtoupper(pathinfo($downloadData[0]['display_filename'], PATHINFO_EXTENSION)),
                'additional_info' => VirtualProductInfo::getAdditionalFileInfo('download/' . $downloadData[0]['filename'], pathinfo($downloadData[0]['display_filename'], PATHINFO_EXTENSION))
            );
        }
        
        $this->context->smarty->assign(['files' => $files]);
        return $this->display(__FILE__, 'virtualproductinfo.tpl');
    }

    public function getContent()
    {
        $output = '';
        
        if (Tools::isSubmit('submit' . $this->name)) {
            $regKey = (string) Tools::getValue('VIRTUALPRODUCTINFO_REG_KEY');
            $regKey = trim($regKey);
            
            if (empty($regKey)) {
                Configuration::deleteByName('VIRTUALPRODUCTINFO_REG_KEY');
                $output = $this->displayConfirmation($this->l('Key removed'));
            } else {
                if (!Validate::isGenericName($regKey) || !VirtualProductInfo::validateRegistrationKey($regKey)) {
                    $output = $this->displayError($this->l('Invalid registration key!'));
                } else {
                    $regKey = trim($regKey);
                    Configuration::updateValue('VIRTUALPRODUCTINFO_REG_KEY', $regKey);
                    $output = $this->displayConfirmation($this->l('Module activated!'));
                }
            }
        }
        
        return $output . $this->displayForm();
    }

    public function displayForm()
    {
        $form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Settings')
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->l('Registration key'),
                        'name' => 'VIRTUALPRODUCTINFO_REG_KEY',
                        'size' => 20,
                        'required' => true
                    ]
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                    'class' => 'btn btn-default pull-right'
                ]
            ]
        ];
        
        if ($this->isModuleActivated()) {
            // Module is activated
        } else if (!$this->isTrialNotExpired() && !$this->isModuleActivated()) {
            $form['form'] = VirtualProductInfo::array_insert_after($form['form'], 'legend', [
                'error' => 'Trial period expired!'
            ]);
        } else {
            $form['form'] = VirtualProductInfo::array_insert_after($form['form'], 'legend', [
                'description' => 'You can try this module for free within 7 days. After trial period ended you should purchase license and enter registration key. <a href="https://github.com/artem78/prestashop-virtualproductinfo?tab=readme-ov-file#purchase-license" target="_blank">More information...</a>'
            ]);
        }
        
        $helper = new HelperForm();
        $helper->table = $this->table;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false, [], ['configure' => $this->name]);
        $helper->submit_action = 'submit' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->fields_value['VIRTUALPRODUCTINFO_REG_KEY'] = Tools::getValue('VIRTUALPRODUCTINFO_REG_KEY', Configuration::get('VIRTUALPRODUCTINFO_REG_KEY'));
        
        return $helper->generateForm([$form]);
    }

    private static function validateRegistrationKey($key)
    {
        if (!$key) {
            return false;
        }
        
        if (!is_string($key)) {
            return false;
        }
        
        $key = trim($key);
        $key = strtoupper($key);
        
        if (!preg_match('/^[A-Z0-9]{16}$/', $key)) {
            return false;
        }
        
        $hash1 = md5($key);
        $hash1 = strtolower($hash1);
        $hash2 = md5($hash1);
        $hash2 = strtolower($hash2);
        
        return substr($hash2, -2, 2) == '24' && 
               substr($hash1, 0, 1) == '5' && 
               substr($hash2, 7, 1) == '9' && 
               substr($hash1, -10, 1) == 'f';
    }

    private function isModuleActivated()
    {
        return VirtualProductInfo::validateRegistrationKey(Configuration::get('VIRTUALPRODUCTINFO_REG_KEY'));
    }

    private static function array_insert_after(array $array, $key, array $new)
    {
        $keys = array_keys($array);
        $index = array_search($key, $keys);
        $pos = false === $index ? count($array) : $index + 1;
        
        return array_merge(array_slice($array, 0, $pos), $new, array_slice($array, $pos));
    }

    private function getInstallTimestamp()
    {
        return Configuration::get('VIRTUALPRODUCTINFO_TS');
    }

    private function setInstallTimestamp()
    {
        return Configuration::updateValue('VIRTUALPRODUCTINFO_TS', time());
    }

    private function isTrialNotExpired()
    {
        $currentTime = time();
        $installTime = $this->getInstallTimestamp();
        
        if (!$installTime) {
            return false;
        }
        
        $trialEndTime = $installTime + 604800; // 7 days in seconds
        
        return $currentTime >= $installTime && $currentTime <= $trialEndTime;
    }
}
