<?php
/**
* 2007-2025 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author    PrestaShop SA <contact@prestashop.com>
*  @copyright 2007-2025 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

use PrestaShop\PrestaShop\Adapter\Entity\DbQuery;
use PrestaShop\PrestaShop\Adapter\Entity\Db;

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
        $this->version = '1.1.4';
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
        $r = parent::install() &&
            $this->registerHook('displayProductAdditionalInfo');
            
        return $r;
    }

    public function uninstall()
    {
        return parent::uninstall();
    }
    
    private static function humanFileSize($bytes, $decimals = 2)
    {
	  $sz = 'KMGTP';
	  $factor = floor((strlen($bytes) - 1) / 3);
	  if ($factor > 0)
	  {
		  $value = sprintf("%.{$decimals}f", $bytes / pow(1024, $factor));
		  $unit = @$sz[$factor-1] . 'b';
	  }
	  else
	  {
		  $value = $bytes;
		  $unit = 'bytes';
	  }
	  return $value . ' ' .  $unit;
	}
	
	private static function getAdditionalFileInfo($filename, $ext = null)
	{ // extension may be empty and given separately outside function
		$result = array();
		
		if (!$ext)
		{ // try to read ext from filename if not given
			$ext = pathinfo($filename, PATHINFO_EXTENSION);
		}
		//print_r($ext);die();
		
		switch (strtolower($ext))
		{
			case 'bmp':
			case 'png':
			case 'jpg':
			case 'jpeg':
			case 'gif':
				$size = getimagesize($filename);
				//print_r($size);die();
				if ($size)
				{
					$result['dimension'] = sprintf('%d x %d px', $size[/*'width'*/0], $size[/*'height'*/1]);
				}
				break;
				
			case 'txt':
				$linecount = 0;
				$wordcount = 0;
				$handle = fopen($filename, "r");
				while(!feof($handle)){
				  $line = fgets($handle);
				  // todo: skip empty lines?
				  $linecount++;
				  $wordcount += str_word_count($line);
				}
				fclose($handle);
				$result['line_count'] = $linecount . ' lines';
				$result['word_count'] = $wordcount . ' words';
				break;
		}
		
		return $result;
	}
	
	private static function getZipContentsInfo($zip_filename)
	{		
		$zip = new ZipArchive;
		if ($zip->open($zip_filename) === TRUE)
		{
			$files = array();
			//$tmp_dir = tempnam(sys_get_temp_dir(), "ps_zip_prw_") . '/';
			$tmp_dir = VirtualProductInfo::tempdir("ps_zip_prw_");
			$zip->extractTo($tmp_dir);
			$i = 0;
			while($name = $zip->getNameIndex($i)) {
				//$tmp_filename = tempnam(sys_get_temp_dir(), "ps_zip_prw_");
				$tmp_filename = $tmp_dir . '/' . $name;
				if (!is_file($tmp_filename))
				{
					$i++;
					continue;
				}
				//$zip->extractTo($tmp_dir/*, $name*/);
				$files[] = array(
					'filename' => $name,
					'size' => VirtualProductInfo::humanFileSize($zip->statIndex($i)['size']),
					'compressed_size' => VirtualProductInfo::humanFileSize($zip->statIndex($i)['comp_size']),
					'type' => strtoupper(pathinfo($name, PATHINFO_EXTENSION)),
					'additional_info' => VirtualProductInfo::getAdditionalFileInfo($tmp_filename, pathinfo($name, PATHINFO_EXTENSION))
				);
				unlink($tmp_filename);
				$i++;
			}
			
			$zip->close();
			//rmdir($tmp_dir);
			VirtualProductInfo::recurseRmdir($tmp_dir);
			return $files;
		}
		else
		{
			return FALSE;
		}
	}
	
	private static function tempdir($prefix = '')
	{
		$tempfile=tempnam(sys_get_temp_dir(),$prefix);
		// tempnam creates file on disk
		if (file_exists($tempfile)) { unlink($tempfile); }
		mkdir($tempfile);
		if (is_dir($tempfile)) { return $tempfile; }
	}
	
	private static function recurseRmdir($dir)
	{
	  $files = array_diff(scandir($dir), array('.','..'));
	  foreach ($files as $file) {
		(is_dir("$dir/$file") && !is_link("$dir/$file")) ? VirtualProductInfo::recurseRmdir("$dir/$file") : unlink("$dir/$file");
	  }
	  return rmdir($dir);
	}
    
    public function hookDisplayProductAdditionalInfo($params)
    {		
		////$productCollection = new PrestaShopCollection('Product');
		////$productCollection->join('categories', 'id_category');
		$sql = new DbQuery();
		$sql->select('*');
		$sql->from('product_download');
		$sql->where('id_product = ' . $params['product']['id_product']);
		//print_r($sql);die();
		////$sql->orderBy('position');
		$results = Db::getInstance()->executeS($sql);
		//print_r($results);die();
		
		////print_r($results);
		$files = array();
		if ($results)
		{
			if (str_ends_with(strtolower($results[0]['display_filename']), '.zip'))
			{ // Archive
				$files[] = array( // zip itself
					'filename' => $results[0]['display_filename'],
					'size' => VirtualProductInfo::humanFileSize(filesize('download/' . $results[0]['filename'])),
					'type' => strtoupper(pathinfo($results[0]['display_filename'], PATHINFO_EXTENSION))//,
					//'additional_info' => array(...)
				);
				
				$zip_files = VirtualProductInfo::getZipContentsInfo('download/' . $results[0]['filename']);
				if ($zip_files === FALSE)
				{
					// unable to read zip
				}
				else
				{
					$files = array_merge($files, $zip_files);
				}
			} else { // Single file
				$files[] = array(
					'filename' => $results[0]['display_filename'],
					'size' => VirtualProductInfo::humanFileSize(filesize('download/' . $results[0]['filename'])),
					'type' => $result['type'] = strtoupper(pathinfo($results[0]['display_filename'], PATHINFO_EXTENSION)),
					'additional_info' => VirtualProductInfo::getAdditionalFileInfo('download/' . $results[0]['filename'],
																pathinfo($results[0]['display_filename'], PATHINFO_EXTENSION))
				);
			}
    
		}



		$this->context->smarty->assign([
			//'product' => $params['product'],
			//'results' => $results,
			
			//'is_virtual_product' => $params['product']['is_virtual'],
            'files' => $files
        ]);

        return $this->display(__FILE__, 'virtualproductinfo.tpl');

	}
	
	
	/**
	 * Insert a value or key/value pair after a specific key in an array.  If key doesn't exist, value is appended
	 * to the end of the array.
	 *
	 * @param array $array
	 * @param string $key
	 * @param array $new
	 *
	 * @return array
	 */
	private static function array_insert_after( array $array, $key, array $new ) {
		$keys = array_keys( $array );
		$index = array_search( $key, $keys );
		$pos = false === $index ? count( $array ) : $index + 1;

		return array_merge( array_slice( $array, 0, $pos ), $new, array_slice( $array, $pos ) );
	}


}
