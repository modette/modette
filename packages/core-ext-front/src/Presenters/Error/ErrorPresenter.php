<?php declare(strict_types = 1);

namespace Modette\Front\Presenters\Error;

use Modette\Front\Presenters\Front\BaseFrontPresenter;
use Nette\Http\IResponse;
use Throwable;

class ErrorPresenter extends BaseFrontPresenter
{

	protected const SUPPORTED_VIEWS = [400, 403, 404, 410, 500];

	public function actionDefault(): void
	{
		// Note error in ajax request
		if ($this->isAjax()) {
			$this->sendPayload();
		}
	}

	public function renderDefault(?Throwable $exception = null): void
	{
		$code = $exception !== null ? $exception->getCode() : 400;

		if ($code >= 500) {
			$this['document-head-meta']->setRobots(['noindex']);
		} else {
			$this['document-head-meta']->setRobots(['noindex', 'nofollow']);
		}

		if ($exception !== null) {
			// Exception was thrown and InternalErrorPresenter forwarded here
			$view = in_array($code, static::SUPPORTED_VIEWS, true)
				? $code
				: ($code >= 500 ? 500 : 400);
		} elseif (
			isset($this->request->parameters['view']) &&
			in_array($this->request->parameters['view'], static::SUPPORTED_VIEWS, true) &&
			$this->getContainerParameters()->isDebugMode()
		) {
			// Developer requested specific view - useful for testing
			$view = $this->request->parameters['view'];
			$this->getHttpResponse()->setCode($view);
		} else {
			// Page was accessed directly by user -> simulate error
			$view = 404;
			$this->getHttpResponse()->setCode(IResponse::S404_NOT_FOUND);
		}

		$this['document-head-title']->setMain(
			$this->getTranslator()->translate(sprintf(
				'modette.ui.presenter.error.%s.title',
				$view
			))
		);

		$this->setView($view);
	}

	public function sendPayload(): void
	{
		$this->payload->error = true;
		parent::sendPayload();
	}

}
