'use strict';

/**
 * Module for communicating with the backend layer.
 */
function SSApi($http, $q, $timeout) {

	/**
	 * Produces a Model object which can be used for accessing the backend API.
	 *
	 * @param {Object} config Describes the requested model instance. Has following properties:
	 *
	 *		- scope {string} scope describing the object. Can be $scope if refering to single obj as a model.
	 *		- modelClass {string} name of the class
	 *		- fields {array} fields on the class
	 *		- scopeAutoUpdate {bool} (default: true) auto-updates the scope on successful fetch/write.
	 */
	this.modelFactory = function(config) {

		function Model(config) {

			config = angular.extend({
				scopeAutoUpdate: true
			}, config);

			this.getConfig = function() {
				return config;
			}

			/**
			 * Synchronise the scope to the backend through the API.
			 */
			this.write = function() {

				var objData = {};
				var deferred = $q.defer();
				var config = this.getConfig();

				angular.forEach(config.fields, function(field) {
					if (typeof config.scope[field]!=='undefined') {
						objData[field] = config.scope[field];
					}
				});

				$http({
					method: 'POST',
					url: '../../api/' + config.modelClass + '/',
					data: [ objData ]
				}).then(
					function(result) {

						if (config.scopeAutoUpdate) {
							angular.forEach(config.fields, function(field) {
								if (typeof result[field]!=='undefined') {
									config.scope[field] = result.data[field];
								}
							});
						}

						deferred.resolve(result);
					},
					function(reason) {
						switch (reason.status) {
							default:
								reason.friendlyMessage = 'Request refused by the server.';
								break;
						}
						deferred.reject(reason);
					}
				);

				return deferred.promise;

			}
		}

		return new Model(config);

	}

	// Initialise the API layer. This is expected to be executed by the application in a run block.
	this.initialise = function() {
		// @todo async fetch of metadata. At present, we just simulate the delay of the ajax request.
		return $timeout(function() {}, 500);
	}
}

angular.module('ssContentApi.services', [])
	.service('ssApi', ['$http', '$q', '$timeout', SSApi]);
