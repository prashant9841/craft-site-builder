<?php

namespace moca\sitebuilder;

use Craft;
use craft\base\Plugin;
use craft\events\RegisterUrlRulesEvent;
use craft\services\Plugins;
use craft\web\UrlManager;
use jamesedmonston\graphqlauthentication\GraphqlAuthentication;
use moca\sitebuilder\records\BuiltSite;
use moca\sitebuilder\services\Builder;
use moca\sitebuilder\services\Themes;
use yii\base\Event;

/**
 * Moca Site Builder: a CP wizard that sets up a client site on this install (site, theme
 * singles, volume, sections, user group and user, GraphQL schema, JWT access) and records it
 * in the Sites Published section.
 *
 * @property-read Builder $builder
 * @property-read Themes $themes
 *
 * @author Moca
 * @since 0.1.0
 */
class SiteBuilder extends Plugin
{
	// Public Properties
	// =========================================================================

	/**
	 * @inheritdoc
	 */
	public bool $hasCpSection = true;

	/**
	 * @inheritdoc
	 */
	public string $schemaVersion = '0.1.1';

	// Public Methods
	// =========================================================================

	/**
	 * @inheritdoc
	 */
	public static function config(): array
	{
		return [
			'components' => [
				'builder' => Builder::class,
				'themes' => Themes::class,
			],
		];
	}

	/**
	 * @inheritdoc
	 */
	public function init(): void
	{
		parent::init();

		$this->_registerCpUrlRules();
		$this->_registerJwtSchemas();
	}

	/**
	 * @inheritdoc
	 */
	public function getCpNavItem(): ?array
	{
		$item = parent::getCpNavItem();
		$item['label'] = 'Site Builder';

		return $item;
	}

	/**
	 * Returns the builder service.
	 *
	 * @return Builder
	 */
	public function getBuilder(): Builder
	{
		/** @var Builder */
		return $this->get('builder');
	}

	/**
	 * Returns the themes service.
	 *
	 * @return Themes
	 */
	public function getThemes(): Themes
	{
		/** @var Themes */
		return $this->get('themes');
	}

	// Private Methods
	// =========================================================================

	/**
	 * Registers the wizard's CP routes.
	 *
	 * @return void
	 */
	private function _registerCpUrlRules(): void
	{
		Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
			$event->rules['site-builder'] = 'site-builder/wizard/details';
			$event->rules['site-builder/content'] = 'site-builder/wizard/content';
			$event->rules['site-builder/review'] = 'site-builder/wizard/review';
			$event->rules['site-builder/done/<id:\d+>'] = 'site-builder/wizard/done';
		});
	}

	/**
	 * Maps each built site's user group to its GraphQL schema in GraphQL Authentication,
	 * so the site's users get a JWT for that schema, restricted to that site.
	 *
	 * GraphQL Authentication's `granularSchemas` lives in its config file, which the plugin
	 * can't write to, so the built sites are merged into its settings at runtime.
	 *
	 * @return void
	 */
	private function _registerJwtSchemas(): void
	{
		Event::on(Plugins::class, Plugins::EVENT_AFTER_LOAD_PLUGINS, function() {
			$settings = GraphqlAuthentication::$settings ?? null;
			if (!$settings || !Craft::$app->getDb()->tableExists(BuiltSite::TABLE)) {
				return;
			}

			foreach (BuiltSite::find()->all() as $built) {
				$settings->granularSchemas["group-{$built->userGroupId}"] = [
					'schemaName' => $built->schemaName,
					'allowRegistration' => false,
					'siteId' => $built->siteId,
					'entryQueries' => null,
					'entryMutations' => null,
					'assetQueries' => null,
					'assetMutations' => null,
				];
			}
		});
	}
}
