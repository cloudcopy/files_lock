<?php declare(strict_types=1);


/**
 * FilesLock - Temporary Files Lock
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2019
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OCA\FilesLock\AppInfo;


use OC\Files\Filesystem;
use OCA\DAV\Connector\Sabre\CachingTree;
use OCA\DAV\Connector\Sabre\ObjectTree;
use OCA\FilesLock\Plugins\FilesLockPlugin;
use OCA\FilesLock\Service\FileService;
use OCA\FilesLock\Service\LockService;
use OCA\FilesLock\Service\MiscService;
use OCA\FilesLock\Storage\LockWrapper;
use OCP\AppFramework\App;
use OCP\AppFramework\QueryException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserSession;
use OCP\SabrePluginEvent;
use OCP\Util;
use Sabre\DAV\Locks\Plugin;


/**
 * Class Application
 *
 * @package OCA\FilesLock\AppInfo
 */
class Application extends App {


	const APP_NAME = 'files_lock';

	const DAV_PROPERTY_LOCK = '{http://nextcloud.org/ns}lock';
	const DAV_PROPERTY_LOCK_OWNER = '{http://nextcloud.org/ns}lock-owner';
	const DAV_PROPERTY_LOCK_OWNER_DISPLAYNAME = '{http://nextcloud.org/ns}lock-owner-displayname';
	const DAV_PROPERTY_LOCK_TIME = '{http://nextcloud.org/ns}lock-time';


	/** @var IEventDispatcher */
	private $eventDispatcher;

	/** @var IUserSession */
	private $userSession;

	/** @var FileService */
	private $fileService;

	/** @var LockService */
	private $lockService;

	/** @var MiscService */
	private $miscService;


	/**
	 * @param array $params
	 */
	public function __construct(array $params = array()) {
		parent::__construct(self::APP_NAME, $params);
	}


	/**
	 *
	 */
	public function registerHooks() {
		$c = $this->getContainer();
		try {
			$this->eventDispatcher = $c->query(IEventDispatcher::class);
			$this->userSession = $c->query(IUserSession::class);
			$this->fileService = $c->query(FileService::class);
			$this->lockService = $c->query(LockService::class);
			$this->miscService = $c->query(MiscService::class);
		} catch (QueryException $e) {
			return;
		}

		$this->eventDispatcher->addListener(
			'OCA\Files::loadAdditionalScripts',
			function() {
				Util::addScript(self::APP_NAME, 'files');
				Util::addStyle(self::APP_NAME, 'files_lock');
			}
		);


		$this->eventDispatcher->addListener(
			'OCA\DAV\Connector\Sabre::addPlugin', function(SabrePluginEvent $e) {
			$server = $e->getServer();
			$absolute = false;
			switch (get_class($server->tree)) {
				case ObjectTree::class:
					$absolute = false;
					break;

				case CachingTree::class:
					$absolute = true;
					break;
			}

			$server->on('propFind', [$this->lockService, 'propFind']);
			$server->addPlugin(
				new Plugin(
					new FilesLockPlugin(
						$this->userSession, $this->fileService, $this->lockService, $this->miscService,
						$absolute
					)
				)
			);
		}
		);

		Util::connectHook('OC_Filesystem', 'preSetup', $this, 'addStorageWrapper');
	}

	/**
	 * @internal
	 */
	public function addStorageWrapper() {
		Filesystem::addStorageWrapper(
			'files_lock', function($mountPoint, $storage) {
			return new LockWrapper(
				[
					'storage'      => $storage,
					'user_session' => $this->userSession,
					'file_service' => $this->fileService,
					'lock_service' => $this->lockService,
					'misc_service' => $this->miscService
				]
			);
		}, 10
		);
	}

}

