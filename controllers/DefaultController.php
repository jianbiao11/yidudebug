<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\debug\controllers;

use Yii;
use yii\debug\models\search\Debug;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Debugger controller
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class DefaultController extends Controller {
	/**
	 * @inheritdoc
	 */
	public $layout = 'main';
	/**
	 * @var \yii\debug\Module
	 */
	public $module;
	/**
	 * @var array the summary data (e.g. URL, time)
	 */
	public $summary;

	/**
	 * @inheritdoc
	 */
	public function actions() {
		$actions = [];
		foreach ($this->module->panels as $panel) {
			$actions = array_merge($actions, $panel->actions);
		}

		return $actions;
	}

	public function beforeAction($action) {
		Yii::$app->response->format = Response::FORMAT_HTML;
		$session = Yii::$app->session;
		//检查session是否开启
		if (!$session->isActive) {
			$session->open();
		}
		return parent::beforeAction($action);
	}

	public function actionIndex() {
		$searchModel = new Debug();

		$logdate = \Yii::$app->request->get('logdate', date("Y-m-d", time()));
		//判断日志路径是否存在
		$logDir = $this->module->dataPath . '/' . $logdate;
		$session = Yii::$app->session;
		$isnodata = false;
		$selectdate = $logdate;
		if (!is_dir($logDir)) {
			$isnodata = true;
			$logdate = $session->get('logdate', date("Y-m-d", time()));
		}

		$session->set('logdate', $logdate);
		$dataProvider = $searchModel->search($_GET, $this->getManifest());

		// load latest request
		$tags = array_keys($this->getManifest());
		$tag = reset($tags);
		$this->loadData($tag);

		return $this->render('index', [
			'selectdate' => $selectdate,
			'logdate' => $logdate,
			'isshowdate' => $this->module->isLocalDebug,
			'panels' => $this->module->panels,
			'dataProvider' => $dataProvider,
			'searchModel' => $searchModel,
			'manifest' => $this->getManifest(),
			'isnodata' => $isnodata,
		]);
	}

	public function actionView($tag = null, $panel = null) {
		if ($tag === null) {
			$tags = array_keys($this->getManifest());
			$tag = reset($tags);
		}
		$this->loadData($tag);
		if (isset($this->module->panels[$panel])) {
			$activePanel = $this->module->panels[$panel];
		} else {
			$activePanel = $this->module->panels[$this->module->defaultPanel];
		}

		return $this->render('view', [
			'tag' => $tag,
			'summary' => $this->summary,
			'manifest' => $this->getManifest(),
			'panels' => $this->module->panels,
			'activePanel' => $activePanel,
		]);
	}

	public function actionToolbar($tag) {
		$this->loadData($tag, 5);

		return $this->renderPartial('toolbar', [
			'tag' => $tag,
			'panels' => $this->module->panels,
			'position' => 'bottom',
		]);
	}

	public function actionDownloadMail($file) {
		$filePath = Yii::getAlias($this->module->panels['mail']->mailPath) . '/' . basename($file);

		if ((mb_strpos($file, '\\') !== false || mb_strpos($file, '/') !== false) || !is_file($filePath)) {
			throw new NotFoundHttpException('Mail file not found');
		}

		return Yii::$app->response->sendFile($filePath);
	}

	private $_manifest;

	protected function getManifest($forceReload = false) {
		if ($this->_manifest === null || $forceReload) {
			if ($forceReload) {
				clearstatcache();
			}

			//判断日志文件是否在本地
			if ($this->module->isLocalDebug) {
				$indexFile = $this->module->dataPath . '/index.data';
			} else {
				$session = Yii::$app->session;
				$logdate = $session->get('logdate');
				$indexFile = $this->module->dataPath . '/' . $logdate . '/index.data';
			}

			$content = '';
			$fp = @fopen($indexFile, 'r');
			if ($fp !== false) {
				@flock($fp, LOCK_SH);
				$content = fread($fp, filesize($indexFile));
				@flock($fp, LOCK_UN);
				fclose($fp);
			}

			if ($content !== '') {
				$this->_manifest = array_reverse(unserialize($content), true);
			} else {
				$this->_manifest = [];
			}
		}

		return $this->_manifest;
	}

	public function loadData($tag, $maxRetry = 0) {
		// retry loading debug data because the debug data is logged in shutdown function
		// which may be delayed in some environment if xdebug is enabled.
		// See: https://github.com/yiisoft/yii2/issues/1504
		for ($retry = 0; $retry <= $maxRetry; ++$retry) {
			$manifest = $this->getManifest($retry > 0);
			if (isset($manifest[$tag])) {
				//判断日志文件是否在本地
				if ($this->module->isLocalDebug) {
					$dataFile = $this->module->dataPath . "/$tag.data";
				} else {
					$session = Yii::$app->session;
					$logdate = $session->get('logdate');
					$dataFile = $this->module->dataPath . "/" . $logdate . "/$tag.data";
					touch($dataFile);
				}

				$data = unserialize(file_get_contents($dataFile));
				foreach ($this->module->panels as $id => $panel) {
					if (isset($data[$id])) {
						$panel->tag = $tag;
						$panel->load($data[$id]);
					}
				}
				$this->summary = $data['summary'];

				return;
			}
			sleep(1);
		}

		throw new NotFoundHttpException("Unable to find debug data tagged with '$tag'.");
	}
}
