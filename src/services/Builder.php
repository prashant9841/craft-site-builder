<?php

namespace moca\sitebuilder\services;

use Craft;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\User;
use craft\enums\PropagationMethod;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\Assets;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\fs\Local;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\models\CategoryGroup;
use craft\models\CategoryGroup_SiteSettings;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\GqlSchema;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Site;
use craft\models\UserGroup;
use craft\models\Volume;
use moca\sitebuilder\records\BuiltSite;
use moca\sitebuilder\SiteBuilder;
use yii\base\Component;
use yii\base\Exception;

/**
 * Builds a client site from the wizard's input: the site, its volume, the Home and Site
 * settings singles, section access, a user group and user, a GraphQL schema, and the site's
 * entry in Sites Published.
 *
 * Every step changes project config, so it needs admin changes allowed and must run in a
 * normal request (project config is saved when the request ends).
 *
 * @author Moca
 * @since 0.1.0
 */
class Builder extends Component
{
	// Const Properties
	// =========================================================================

	/**
	 * Site group new sites go in.
	 */
	public const SITE_GROUP_ID = 2;

	/**
	 * Shared page section; every built site gets it, with its theme's page entry type.
	 */
	public const PAGES_SECTION = 'pagebuilder';

	/**
	 * Generic site settings entry type, used by each site's Site settings single.
	 */
	public const SETTINGS_ENTRY_TYPE = 'siteSettings';

	/**
	 * Volume client logos are uploaded to.
	 */
	public const CLIENTS_VOLUME = 'clients';

	/**
	 * Section every built site is recorded in, with its setup details.
	 */
	public const PUBLISHED_SECTION = 'sitesPublished';

	// Public Methods
	// =========================================================================

	/**
	 * Sections a site can be given: channels and structures, except the shared page section
	 * (always included) and Sites Published.
	 *
	 * @return Section[]
	 */
	public function getSelectableSections(): array
	{
		return array_values(array_filter(
			Craft::$app->getEntries()->getAllSections(),
			fn(Section $section) => $section->type !== Section::TYPE_SINGLE
				&& !in_array($section->handle, [self::PAGES_SECTION, self::PUBLISHED_SECTION], true),
		));
	}

	/**
	 * Handle for a new site from its name, e.g. "Acme Plumbing" => `acmePlumbing`.
	 *
	 * @param string $name
	 * @return string
	 */
	public function handleFromName(string $name): string
	{
		$handle = StringHelper::toCamelCase(StringHelper::toAscii($name));

		return preg_match('/^[a-z]/', $handle) ? $handle : "site{$handle}";
	}

	/**
	 * Why a site handle can't be used, or null if it can.
	 *
	 * @param string $handle
	 * @return string|null
	 */
	public function handleError(string $handle): ?string
	{
		if ($handle === '' || $handle === 'site') {
			return 'Enter a site name.';
		}
		if (Craft::$app->getSites()->getSiteByHandle($handle, false)) {
			return "A site with the handle \"{$handle}\" already exists.";
		}
		if (Craft::$app->getEntries()->getSectionByHandle("{$handle}Home")) {
			return "A section with the handle \"{$handle}Home\" already exists.";
		}

		return null;
	}

	/**
	 * Builds the site.
	 *
	 * @param array{
	 *     name: string,
	 *     handle: string,
	 *     theme: string,
	 *     logoPath: ?string,
	 *     logoFilename: ?string,
	 *     sectionIds: int[],
	 *     categoryGroupIds: int[],
	 *     globalSetIds: int[],
	 *     user: array{id?: int, email?: string, firstName?: string, lastName?: string, password?: string},
	 * } $input
	 * @return BuiltSite
	 * @throws Exception if any step fails
	 */
	public function build(array $input): BuiltSite
	{
		$name = $input['name'];
		$handle = $input['handle'];
		$theme = SiteBuilder::getInstance()->getThemes()->getThemeByHandle($input['theme'])
			?? throw new Exception("Unknown theme \"{$input['theme']}\".");

		$site = $this->_createSite($name, $handle);
		$volume = $this->_createVolume("{$name} images", "{$handle}Images", "sites/{$handle}");
		$logo = $input['logoPath'] ? $this->_saveLogo($input['logoPath'], $input['logoFilename'], $handle) : null;

		$home = $this->_createSingle("{$name} home", "{$handle}Home", $this->_entryType($theme['home']), $site, '__home__');
		$settings = $this->_createSingle("{$name} site settings", "{$handle}Settings", $this->_entryType(self::SETTINGS_ENTRY_TYPE), $site, null);

		$entries = Craft::$app->getEntries();
		$pages = $entries->getSectionByHandle(self::PAGES_SECTION)
			?? throw new Exception('The "' . self::PAGES_SECTION . '" section is missing.');
		$sections = [$pages];
		foreach ($input['sectionIds'] as $sectionId) {
			if ($section = $entries->getSectionById((int) $sectionId)) {
				$sections[] = $section;
			}
		}
		foreach ($sections as $section) {
			$this->_enableSection($section, $site);
		}

		$categoryGroups = [];
		foreach ($input['categoryGroupIds'] as $groupId) {
			if ($group = Craft::$app->getCategories()->getGroupById((int) $groupId)) {
				$this->_enableCategoryGroup($group, $site);
				$categoryGroups[] = $group;
			}
		}

		$globalSets = [];
		foreach ($input['globalSetIds'] as $setId) {
			if ($set = Craft::$app->getGlobals()->getSetById((int) $setId)) {
				$globalSets[] = $set;
			}
		}

		$singles = [$home, $settings];
		$userGroup = $this->_createUserGroup($name, $handle, $site, $singles, $sections, $categoryGroups, $globalSets, $volume);
		$user = $this->_resolveUser($input['user'], $userGroup);
		$schema = $this->_createSchema("Site: {$name}", $site, $singles, $sections, $categoryGroups, $globalSets, $volume, $logo?->getVolume());

		$built = new BuiltSite([
			'siteId' => $site->id,
			'theme' => $theme['handle'],
			'volumeId' => $volume->id,
			'userGroupId' => $userGroup->id,
			'userId' => $user->id,
			'schemaId' => $schema->id,
			'schemaName' => $schema->name,
		]);
		$built->save(false);

		$built->details = $this->_details($built, $site, $theme, $home, $settings, $sections, $categoryGroups, $globalSets, $user, $volume, $logo);
		$built->entryId = $this->_publish($built, $site, $theme, $user, $logo)->id;
		$built->save(false);

		return $built;
	}

	// Private Methods
	// =========================================================================

	/**
	 * Creates the site in the Sitebuilder site group.
	 *
	 * @param string $name
	 * @param string $handle
	 * @return Site
	 * @throws Exception
	 */
	private function _createSite(string $name, string $handle): Site
	{
		$sites = Craft::$app->getSites();
		$site = new Site([
			'groupId' => self::SITE_GROUP_ID,
			'name' => $name,
			'handle' => $handle,
			'language' => $sites->getPrimarySite()->language,
			'hasUrls' => true,
			'baseUrl' => "@web/{$handle}",
			'primary' => false,
			'enabled' => true,
		]);
		if (!$sites->saveSite($site)) {
			throw new Exception('Site: ' . implode(' ', $site->getFirstErrors()));
		}

		return $site;
	}

	/**
	 * Creates a local filesystem and a volume on it, or returns the volume if it exists.
	 *
	 * @param string $name
	 * @param string $handle Volume handle; the filesystem is `<handle>Fs`
	 * @param string $path Folder under the web root
	 * @return Volume
	 * @throws Exception
	 */
	private function _createVolume(string $name, string $handle, string $path): Volume
	{
		$volumes = Craft::$app->getVolumes();
		if ($existing = $volumes->getVolumeByHandle($handle)) {
			return $existing;
		}

		$fsService = Craft::$app->getFs();
		$fs = $fsService->createFilesystem([
			'type' => Local::class,
			'name' => $name,
			'handle' => "{$handle}Fs",
			'hasUrls' => true,
			'url' => "@web/{$path}",
			'path' => "@webroot/{$path}",
		]);
		if (!$fsService->saveFilesystem($fs)) {
			throw new Exception("Filesystem {$handle}Fs: " . implode(' ', $fs->getFirstErrors()));
		}

		$volume = new Volume([
			'name' => $name,
			'handle' => $handle,
			'fsHandle' => $fs->handle,
		]);
		if (!$volumes->saveVolume($volume)) {
			throw new Exception("Volume {$handle}: " . implode(' ', $volume->getFirstErrors()));
		}

		return $volume;
	}

	/**
	 * Uploads the logo to the clients volume.
	 *
	 * @param string $path Temp file
	 * @param string $filename Original filename
	 * @param string $handle Site handle, used as the filename prefix
	 * @return Asset
	 * @throws Exception
	 */
	private function _saveLogo(string $path, string $filename, string $handle): Asset
	{
		$volume = $this->_createVolume('Clients', self::CLIENTS_VOLUME, self::CLIENTS_VOLUME);
		$folder = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);

		$asset = new Asset();
		$asset->tempFilePath = $path;
		$asset->setFilename("{$handle}-" . $filename);
		$asset->newFolderId = $folder->id;
		$asset->setVolumeId($volume->id);
		$asset->avoidFilenameConflicts = true;
		$asset->setScenario(Asset::SCENARIO_CREATE);
		if (!Craft::$app->getElements()->saveElement($asset)) {
			throw new Exception('Logo: ' . implode(' ', $asset->getFirstErrors()));
		}

		return $asset;
	}

	/**
	 * Creates a single for one site.
	 *
	 * @param string $name
	 * @param string $handle
	 * @param EntryType $entryType
	 * @param Site $site
	 * @param string|null $uriFormat Null for no URL
	 * @return Section
	 * @throws Exception
	 */
	private function _createSingle(string $name, string $handle, EntryType $entryType, Site $site, ?string $uriFormat): Section
	{
		$section = new Section([
			'name' => $name,
			'handle' => $handle,
			'type' => Section::TYPE_SINGLE,
			'propagationMethod' => PropagationMethod::None,
		]);
		$section->setSiteSettings([
			$site->id => new Section_SiteSettings([
				'siteId' => $site->id,
				'enabledByDefault' => true,
				'hasUrls' => $uriFormat !== null,
				'uriFormat' => $uriFormat,
			]),
		]);
		$section->setEntryTypes([$entryType]);
		if (!Craft::$app->getEntries()->saveSection($section)) {
			throw new Exception("Section {$handle}: " . implode(' ', $section->getFirstErrors()));
		}

		return $section;
	}

	/**
	 * Enables a section for the site, copying another site's URI format.
	 *
	 * @param Section $section
	 * @param Site $site
	 * @return void
	 * @throws Exception
	 */
	private function _enableSection(Section $section, Site $site): void
	{
		$siteSettings = $section->getSiteSettings();
		if (isset($siteSettings[$site->id])) {
			return;
		}

		$template = reset($siteSettings) ?: null;
		$siteSettings[$site->id] = new Section_SiteSettings([
			'siteId' => $site->id,
			'enabledByDefault' => true,
			'hasUrls' => $template->hasUrls ?? true,
			'uriFormat' => $template->uriFormat ?? "{$section->handle}/{slug}",
			'template' => $template->template ?? null,
		]);
		$section->setSiteSettings($siteSettings);
		if (!Craft::$app->getEntries()->saveSection($section)) {
			throw new Exception("Section {$section->handle}: " . implode(' ', $section->getFirstErrors()));
		}
	}

	/**
	 * Enables a category group for the site, copying another site's URI format.
	 *
	 * @param CategoryGroup $group
	 * @param Site $site
	 * @return void
	 * @throws Exception
	 */
	private function _enableCategoryGroup(CategoryGroup $group, Site $site): void
	{
		$siteSettings = $group->getSiteSettings();
		if (isset($siteSettings[$site->id])) {
			return;
		}

		$template = reset($siteSettings) ?: null;
		$siteSettings[$site->id] = new CategoryGroup_SiteSettings([
			'siteId' => $site->id,
			'hasUrls' => $template->hasUrls ?? false,
			'uriFormat' => $template->uriFormat ?? null,
			'template' => $template->template ?? null,
		]);
		$group->setSiteSettings($siteSettings);
		if (!Craft::$app->getCategories()->saveGroup($group)) {
			throw new Exception("Category group {$group->handle}: " . implode(' ', $group->getFirstErrors()));
		}
	}

	/**
	 * Creates the site's user group, with access to the site and its content only (no CP).
	 *
	 * @param string $name
	 * @param string $handle
	 * @param Site $site
	 * @param Section[] $singles
	 * @param Section[] $sections
	 * @param CategoryGroup[] $categoryGroups
	 * @param GlobalSet[] $globalSets
	 * @param Volume $volume
	 * @return UserGroup
	 * @throws Exception
	 */
	private function _createUserGroup(string $name, string $handle, Site $site, array $singles, array $sections, array $categoryGroups, array $globalSets, Volume $volume): UserGroup
	{
		$group = new UserGroup([
			'name' => "Site: {$name}",
			'handle' => "{$handle}Editors",
		]);
		if (!Craft::$app->getUserGroups()->saveGroup($group)) {
			throw new Exception('User group: ' . implode(' ', $group->getFirstErrors()));
		}

		$permissions = ["editSite:{$site->uid}"];
		foreach ($singles as $section) {
			array_push($permissions, "viewEntries:{$section->uid}", "saveEntries:{$section->uid}");
		}
		foreach ($sections as $section) {
			foreach (['viewEntries', 'createEntries', 'saveEntries', 'deleteEntries', 'viewPeerEntries', 'savePeerEntries', 'deletePeerEntries', 'viewPeerEntryDrafts', 'savePeerEntryDrafts', 'deletePeerEntryDrafts'] as $permission) {
				$permissions[] = "{$permission}:{$section->uid}";
			}
		}
		foreach ($categoryGroups as $categoryGroup) {
			foreach (['viewCategories', 'saveCategories', 'deleteCategories'] as $permission) {
				$permissions[] = "{$permission}:{$categoryGroup->uid}";
			}
		}
		foreach ($globalSets as $globalSet) {
			$permissions[] = "editGlobalSet:{$globalSet->uid}";
		}
		foreach (['viewAssets', 'saveAssets', 'deleteAssets', 'replaceFiles', 'editImages', 'createFolders', 'viewPeerAssets', 'savePeerAssets', 'replacePeerFiles', 'deletePeerAssets', 'editPeerImages'] as $permission) {
			$permissions[] = "{$permission}:{$volume->uid}";
		}

		if (!Craft::$app->getUserPermissions()->saveGroupPermissions($group->id, $permissions)) {
			throw new Exception('User group permissions could not be saved.');
		}

		return $group;
	}

	/**
	 * Returns the chosen user, or creates and activates a new one, and adds them to the group.
	 *
	 * @param array{id?: int, email?: string, firstName?: string, lastName?: string, password?: string} $input
	 * @param UserGroup $group
	 * @return User
	 * @throws Exception
	 */
	private function _resolveUser(array $input, UserGroup $group): User
	{
		$users = Craft::$app->getUsers();

		if (!empty($input['id'])) {
			$user = $users->getUserById((int) $input['id'])
				?? throw new Exception("User {$input['id']} not found.");
		} else {
			$user = new User([
				'email' => $input['email'],
				'username' => $input['email'],
				'firstName' => $input['firstName'] ?? null,
				'lastName' => $input['lastName'] ?? null,
				'newPassword' => $input['password'],
			]);
			$user->setScenario(User::SCENARIO_REGISTRATION);
			if (!Craft::$app->getElements()->saveElement($user)) {
				throw new Exception('User: ' . implode(' ', $user->getFirstErrors()));
			}
			$users->activateUser($user);
		}

		$groupIds = array_map(fn(UserGroup $g) => $g->id, $user->getGroups());
		$groupIds[] = $group->id;
		$users->assignUserToGroups($user->id, array_unique($groupIds));

		return $user;
	}

	/**
	 * Creates a private GraphQL schema for the site's content only.
	 *
	 * @param string $name
	 * @param Site $site
	 * @param Section[] $singles
	 * @param Section[] $sections
	 * @param CategoryGroup[] $categoryGroups
	 * @param GlobalSet[] $globalSets
	 * @param Volume $volume
	 * @param Volume|null $logoVolume Read access, so the site can show its logo
	 * @return GqlSchema
	 * @throws Exception
	 */
	private function _createSchema(string $name, Site $site, array $singles, array $sections, array $categoryGroups, array $globalSets, Volume $volume, ?Volume $logoVolume): GqlSchema
	{
		$scope = [
			"sites.{$site->uid}:read",
			'elements.drafts:read',
			'directive:parseRefs',
			'directive:transform',
		];
		$entryTypes = [];
		foreach ($singles as $section) {
			array_push($scope, "sections.{$section->uid}:read", "sections.{$section->uid}:save");
			array_push($entryTypes, ...$section->getEntryTypes());
		}
		foreach ($sections as $section) {
			foreach (['read', 'edit', 'create', 'save', 'delete'] as $action) {
				$scope[] = "sections.{$section->uid}:{$action}";
			}
			array_push($entryTypes, ...$section->getEntryTypes());
		}
		foreach ($this->_matrixFields($entryTypes) as $field) {
			foreach (['read', 'edit', 'create', 'save', 'delete'] as $action) {
				$scope[] = "nestedentryfields.{$field->uid}:{$action}";
			}
		}
		foreach ($categoryGroups as $group) {
			foreach (['read', 'edit', 'save', 'delete'] as $action) {
				$scope[] = "categorygroups.{$group->uid}:{$action}";
			}
		}
		foreach ($globalSets as $set) {
			array_push($scope, "globalsets.{$set->uid}:read", "globalsets.{$set->uid}:edit");
		}
		foreach (['read', 'edit', 'create', 'save', 'delete'] as $action) {
			$scope[] = "volumes.{$volume->uid}:{$action}";
		}
		if ($logoVolume) {
			$scope[] = "volumes.{$logoVolume->uid}:read";
		}

		$schema = new GqlSchema([
			'name' => $name,
			'scope' => array_values(array_unique($scope)),
			'isPublic' => false,
		]);
		if (!Craft::$app->getGql()->saveSchema($schema)) {
			throw new Exception('GraphQL schema: ' . implode(' ', $schema->getFirstErrors()));
		}

		return $schema;
	}

	/**
	 * Every Matrix field in the entry types' layouts, including Matrix fields nested inside
	 * them, so the schema can read and write all nested entries.
	 *
	 * @param EntryType[] $entryTypes
	 * @return Matrix[]
	 */
	private function _matrixFields(array $entryTypes): array
	{
		$fields = [];
		$seen = [];
		while ($entryType = array_shift($entryTypes)) {
			if (isset($seen[$entryType->id])) {
				continue;
			}
			$seen[$entryType->id] = true;
			foreach ($entryType->getFieldLayout()->getCustomFields() as $field) {
				if ($field instanceof Matrix) {
					$fields[$field->uid] = $field;
					array_push($entryTypes, ...$field->getEntryTypes());
				}
			}
		}

		return array_values($fields);
	}

	/**
	 * The site's setup details as Markdown, shown on the result page.
	 *
	 * @param BuiltSite $built
	 * @param Site $site
	 * @param array{handle: string, name: string, home: string, page: string} $theme
	 * @param Section $home
	 * @param Section $settings
	 * @param Section[] $sections
	 * @param CategoryGroup[] $categoryGroups
	 * @param GlobalSet[] $globalSets
	 * @param User $user
	 * @param Volume $volume
	 * @param Asset|null $logo
	 * @return string
	 */
	private function _details(BuiltSite $built, Site $site, array $theme, Section $home, Section $settings, array $sections, array $categoryGroups, array $globalSets, User $user, Volume $volume, ?Asset $logo): string
	{
		$handles = fn(array $items) => implode(', ', array_map(fn($item) => "`{$item->handle}`", $items)) ?: '(none)';

		return implode("\n", [
			"# {$site->name}",
			'',
			"- Site: `{$site->handle}` (ID {$site->id}, UID {$site->uid})",
			"- Base URL: {$site->getBaseUrl()}",
			"- Theme: {$theme['name']} (`{$theme['handle']}`); home entry type `{$theme['home']}`, page entry type `{$theme['page']}`",
			"- Home single: `{$home->handle}`; site settings single: `{$settings->handle}`",
			'- Sections: ' . $handles($sections),
			'- Category groups: ' . $handles($categoryGroups),
			'- Global sets: ' . $handles($globalSets),
			"- Images volume: `{$volume->handle}`",
			'- Logo: ' . ($logo?->getUrl() ?? '(none)'),
			"- User: {$user->email} (ID {$user->id}), user group ID {$built->userGroupId}",
			"- GraphQL schema: {$built->schemaName} (ID {$built->schemaId})",
			'- GraphQL endpoint: ' . $this->_gqlEndpoint(),
			'',
			'## Log in (GraphQL Authentication)',
			'',
			'```graphql',
			'mutation { authenticate(email: "' . $user->email . '", password: "...") { jwt jwtExpiresAt refreshToken user { id email } } }',
			'```',
			'',
			'Send the JWT as `Authorization: JWT <jwt>`. Queries only return this site\'s content.',
			"Mutations must pass `siteId: {$site->id}`; without it they save to the primary site and are refused.",
		]);
	}

	/**
	 * Records the site in Sites Published (created with its fields on first use).
	 *
	 * @param BuiltSite $built
	 * @param Site $site
	 * @param array{handle: string, name: string, home: string, page: string} $theme
	 * @param User $user
	 * @param Asset|null $logo
	 * @return Entry
	 * @throws Exception
	 */
	private function _publish(BuiltSite $built, Site $site, array $theme, User $user, ?Asset $logo): Entry
	{
		$section = $this->_publishedSection();

		$entry = new Entry([
			'sectionId' => $section->id,
			'typeId' => $section->getEntryTypes()[0]->id,
			'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
			'title' => $site->name,
		]);
		$entry->setFieldValues([
			'sbSiteHandle' => $site->handle,
			'sbTheme' => $theme['handle'],
			'sbSiteUrl' => $site->getBaseUrl(),
			'sbGqlEndpoint' => $this->_gqlEndpoint(),
			'sbGqlSchema' => $built->schemaName,
			'sbUserEmail' => $user->email,
			'sbLogo' => $logo ? [$logo->id] : [],
			'sbDetails' => $built->details,
		]);
		if (!Craft::$app->getElements()->saveElement($entry)) {
			throw new Exception('Sites Published entry: ' . implode(' ', $entry->getFirstErrors()));
		}

		return $entry;
	}

	/**
	 * The Sites Published channel, created with its fields on first use.
	 *
	 * @return Section
	 * @throws Exception
	 */
	private function _publishedSection(): Section
	{
		$entries = Craft::$app->getEntries();
		if ($section = $entries->getSectionByHandle(self::PUBLISHED_SECTION)) {
			return $section;
		}

		$fields = [
			$this->_field(PlainText::class, 'sbSiteHandle', 'Site handle'),
			$this->_field(PlainText::class, 'sbTheme', 'Theme'),
			$this->_field(PlainText::class, 'sbSiteUrl', 'Site URL'),
			$this->_field(PlainText::class, 'sbGqlEndpoint', 'GraphQL endpoint'),
			$this->_field(PlainText::class, 'sbGqlSchema', 'GraphQL schema'),
			$this->_field(PlainText::class, 'sbUserEmail', 'User email'),
			$this->_field(Assets::class, 'sbLogo', 'Logo', ['sources' => '*', 'maxRelations' => 1]),
			$this->_field(PlainText::class, 'sbDetails', 'Details (Markdown)', ['multiline' => true, 'initialRows' => 16, 'code' => true]),
		];

		$layout = new FieldLayout(['type' => Entry::class]);
		$layout->setTabs([
			new FieldLayoutTab([
				'name' => 'Site',
				'layout' => $layout,
				'elements' => [
					new EntryTitleField(),
					...array_map(fn(FieldInterface $field) => new CustomField($field), $fields),
				],
			]),
		]);
		$entryType = $entries->getEntryTypeByHandle('sitePublished') ?? new EntryType([
			'name' => 'Site published',
			'handle' => 'sitePublished',
		]);
		$entryType->setFieldLayout($layout);
		if (!$entries->saveEntryType($entryType)) {
			throw new Exception('Entry type sitePublished: ' . implode(' ', $entryType->getFirstErrors()));
		}

		$primary = Craft::$app->getSites()->getPrimarySite();
		$section = new Section([
			'name' => 'Sites Published',
			'handle' => self::PUBLISHED_SECTION,
			'type' => Section::TYPE_CHANNEL,
			'propagationMethod' => PropagationMethod::None,
		]);
		$section->setSiteSettings([
			$primary->id => new Section_SiteSettings(['siteId' => $primary->id, 'enabledByDefault' => true, 'hasUrls' => false]),
		]);
		$section->setEntryTypes([$entryType]);
		if (!$entries->saveSection($section)) {
			throw new Exception('Section ' . self::PUBLISHED_SECTION . ': ' . implode(' ', $section->getFirstErrors()));
		}

		return $section;
	}

	/**
	 * Returns a field by handle, creating it if needed.
	 *
	 * @param string $type Field class
	 * @param string $handle
	 * @param string $name
	 * @param array $config
	 * @return FieldInterface
	 * @throws Exception
	 */
	private function _field(string $type, string $handle, string $name, array $config = []): FieldInterface
	{
		$fields = Craft::$app->getFields();
		if ($field = $fields->getFieldByHandle($handle)) {
			return $field;
		}

		$field = $fields->createField(['type' => $type, 'handle' => $handle, 'name' => $name] + $config);
		if (!$fields->saveField($field)) {
			throw new Exception("Field {$handle}: " . implode(' ', $field->getFirstErrors()));
		}

		return $field;
	}

	/**
	 * Returns an entry type by handle.
	 *
	 * @param string $handle
	 * @return EntryType
	 * @throws Exception
	 */
	private function _entryType(string $handle): EntryType
	{
		return Craft::$app->getEntries()->getEntryTypeByHandle($handle)
			?? throw new Exception("Entry type \"{$handle}\" is missing.");
	}

	/**
	 * The GraphQL endpoint URL (`/api` on this install).
	 *
	 * @return string
	 */
	private function _gqlEndpoint(): string
	{
		return UrlHelper::siteUrl('api', siteId: Craft::$app->getSites()->getPrimarySite()->id);
	}
}
