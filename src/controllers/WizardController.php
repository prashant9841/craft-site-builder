<?php

namespace moca\sitebuilder\controllers;

use Craft;
use craft\elements\User;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use craft\web\Controller;
use craft\web\UploadedFile;
use moca\sitebuilder\records\BuiltSite;
use moca\sitebuilder\services\Builder;
use moca\sitebuilder\SiteBuilder;
use Throwable;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The site setup wizard: details (name, logo, theme), content (sections, category groups,
 * global sets, user), review and publish, then the result.
 *
 * Wizard input is kept in the session between steps.
 *
 * @author Moca
 * @since 0.1.0
 */
class WizardController extends Controller
{
	// Const Properties
	// =========================================================================

	/**
	 * Session key for the wizard's input.
	 */
	public const SESSION_KEY = 'siteBuilder.wizard';

	// Public Methods
	// =========================================================================

	/**
	 * @inheritdoc
	 * @throws ForbiddenHttpException if admin changes aren't allowed
	 */
	public function beforeAction($action): bool
	{
		$this->requireAdmin();

		return parent::beforeAction($action);
	}

	/**
	 * Step 1: site name, logo and theme.
	 *
	 * @return Response
	 */
	public function actionDetails(): Response
	{
		return $this->renderTemplate('site-builder/_details', [
			'wizard' => $this->_wizard(),
			'themes' => SiteBuilder::getInstance()->getThemes()->getAllThemes(),
		]);
	}

	/**
	 * Saves step 1.
	 *
	 * @return Response|null
	 */
	public function actionSaveDetails(): ?Response
	{
		$this->requirePostRequest();
		$builder = SiteBuilder::getInstance()->getBuilder();

		$name = trim((string) $this->request->getBodyParam('name'));
		$theme = (string) $this->request->getBodyParam('theme');
		$handle = $builder->handleFromName($name);
		$wizard = ['name' => $name, 'theme' => $theme, 'handle' => $handle] + $this->_wizard();

		$error = $builder->handleError($handle)
			?? (SiteBuilder::getInstance()->getThemes()->getThemeByHandle($theme) ? null : 'Pick a theme.');

		$logo = UploadedFile::getInstanceByName('logo');
		if (!$error && $logo) {
			$path = Craft::$app->getPath()->getTempPath() . '/sitebuilder-' . StringHelper::randomString(12) . '.' . $logo->getExtension();
			if (!$logo->saveAs($path)) {
				$error = 'The logo could not be uploaded.';
			} else {
				$wizard['logoPath'] = $path;
				$wizard['logoFilename'] = $logo->name;
			}
		}

		$this->_saveWizard($wizard);
		if ($error) {
			$this->setFailFlash($error);
			return null;
		}

		return $this->redirect('site-builder/content');
	}

	/**
	 * Step 2: sections, category groups, global sets and the site's user.
	 *
	 * @return Response
	 */
	public function actionContent(): Response
	{
		$wizard = $this->_wizard();
		if (empty($wizard['name'])) {
			return $this->redirect('site-builder');
		}

		return $this->renderTemplate('site-builder/_content', [
			'wizard' => $wizard,
			'sections' => SiteBuilder::getInstance()->getBuilder()->getSelectableSections(),
			'categoryGroups' => Craft::$app->getCategories()->getAllGroups(),
			'globalSets' => Craft::$app->getGlobals()->getAllSets(),
			'users' => User::find()->status(null)->orderBy(['email' => SORT_ASC])->all(),
			'pagesSection' => Builder::PAGES_SECTION,
		]);
	}

	/**
	 * Saves step 2.
	 *
	 * @return Response|null
	 */
	public function actionSaveContent(): ?Response
	{
		$this->requirePostRequest();
		$request = $this->request;

		$user = $request->getBodyParam('userMode') === 'existing'
			? ['id' => (int) $request->getBodyParam('userId')]
			: [
				'email' => trim((string) $request->getBodyParam('email')),
				'firstName' => trim((string) $request->getBodyParam('firstName')),
				'lastName' => trim((string) $request->getBodyParam('lastName')),
				'password' => (string) $request->getBodyParam('password'),
			];

		$wizard = [
			'sectionIds' => array_map('intval', (array) $request->getBodyParam('sectionIds', [])),
			'categoryGroupIds' => array_map('intval', (array) $request->getBodyParam('categoryGroupIds', [])),
			'globalSetIds' => array_map('intval', (array) $request->getBodyParam('globalSetIds', [])),
			'user' => $user,
		] + $this->_wizard();
		$this->_saveWizard($wizard);

		$error = $this->_userError($user);
		if ($error) {
			$this->setFailFlash($error);
			return null;
		}

		return $this->redirect('site-builder/review');
	}

	/**
	 * Step 3: review everything before publishing.
	 *
	 * @return Response
	 */
	public function actionReview(): Response
	{
		$wizard = $this->_wizard();
		if (empty($wizard['user'])) {
			return $this->redirect('site-builder');
		}

		$entries = Craft::$app->getEntries();

		return $this->renderTemplate('site-builder/_review', [
			'wizard' => $wizard,
			'theme' => SiteBuilder::getInstance()->getThemes()->getThemeByHandle($wizard['theme']),
			'sections' => array_filter(array_map(fn($id) => $entries->getSectionById($id), $wizard['sectionIds'])),
			'categoryGroups' => array_filter(array_map(fn($id) => Craft::$app->getCategories()->getGroupById($id), $wizard['categoryGroupIds'])),
			'globalSets' => array_filter(array_map(fn($id) => Craft::$app->getGlobals()->getSetById($id), $wizard['globalSetIds'])),
			'existingUser' => !empty($wizard['user']['id']) ? Craft::$app->getUsers()->getUserById($wizard['user']['id']) : null,
			'pagesSection' => Builder::PAGES_SECTION,
		]);
	}

	/**
	 * Builds the site.
	 *
	 * @return Response|null
	 * @throws ForbiddenHttpException if admin changes aren't allowed
	 */
	public function actionPublish(): ?Response
	{
		$this->requirePostRequest();
		if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
			throw new ForbiddenHttpException('Sites can only be built where admin changes are allowed.');
		}

		$wizard = $this->_wizard();
		try {
			$built = SiteBuilder::getInstance()->getBuilder()->build($wizard + [
				'logoPath' => null,
				'logoFilename' => null,
				'sectionIds' => [],
				'categoryGroupIds' => [],
				'globalSetIds' => [],
			]);
		} catch (Throwable $e) {
			Craft::error($e, __METHOD__);
			$this->setFailFlash('Build failed: ' . $e->getMessage() . ' Anything created before the failure was kept.');
			return null;
		}

		if (!empty($wizard['logoPath'])) {
			FileHelper::unlink($wizard['logoPath']);
		}
		Craft::$app->getSession()->remove(self::SESSION_KEY);

		return $this->redirect("site-builder/done/{$built->id}");
	}

	/**
	 * The result: what was built, with its setup details.
	 *
	 * @param int $id
	 * @return Response
	 * @throws NotFoundHttpException
	 */
	public function actionDone(int $id): Response
	{
		$built = BuiltSite::findOne($id) ?? throw new NotFoundHttpException();

		return $this->renderTemplate('site-builder/_done', [
			'built' => $built,
			'site' => Craft::$app->getSites()->getSiteById($built->siteId, true),
			'entry' => $built->entryId ? Craft::$app->getEntries()->getEntryById($built->entryId, '*') : null,
		]);
	}

	/**
	 * Clears the wizard and starts over.
	 *
	 * @return Response
	 */
	public function actionReset(): Response
	{
		Craft::$app->getSession()->remove(self::SESSION_KEY);

		return $this->redirect('site-builder');
	}

	// Private Methods
	// =========================================================================

	/**
	 * The wizard's input so far.
	 *
	 * @return array
	 */
	private function _wizard(): array
	{
		return Craft::$app->getSession()->get(self::SESSION_KEY, []);
	}

	/**
	 * Replaces the wizard's input.
	 *
	 * @param array $wizard
	 * @return void
	 */
	private function _saveWizard(array $wizard): void
	{
		Craft::$app->getSession()->set(self::SESSION_KEY, $wizard);
	}

	/**
	 * Why the user input can't be used, or null.
	 *
	 * @param array $user
	 * @return string|null
	 */
	private function _userError(array $user): ?string
	{
		if (array_key_exists('id', $user)) {
			return Craft::$app->getUsers()->getUserById($user['id']) ? null : 'Pick a user.';
		}
		if (!filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
			return 'Enter a valid email for the new user.';
		}
		if (Craft::$app->getUsers()->getUserByUsernameOrEmail($user['email'])) {
			return 'A user with that email already exists. Pick them as an existing user instead.';
		}
		if (strlen($user['password']) < 6) {
			return 'The new user\'s password must be at least 6 characters.';
		}

		return null;
	}
}
