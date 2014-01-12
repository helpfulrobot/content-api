'use strict';

/**
 * Module for communicating with the backend layer.
 */
function SSApi($http, $q, $timeout) {

	/**
	 * Prototypes for dataObjects.
	 */
	var prototypes = {}

	/**
	 * Produces a Model object which can be used for accessing the backend API.
	 *
	 * @param {string} modelClass to create
	 * @param {Object} config Describes the requested model instance. Has following properties:
	 *
	 *		- model {string} Class to create.
	 */
	this.createDataObject = function(config) {

		return new prototypes[config.model](config);

	}

	// Initialise the API layer. This is expected to be executed by the application in a run block.
	this.initialise = function() {

		prototypes.DataObject = function DataObject(config) {

			if (typeof config!=='undefined') this.config = config;

			/**
			 * Write the object to the backend.
			 */
			this.write = function() {

				var objData = {};
				var deferred = $q.defer();
				var self = this;

				// Extract writable fields.
				angular.forEach(this.db, function(type, field) {
					if (typeof self[field]!=='undefined') {
						objData[field] = self[field];
					}
				});

				$http({
					method: 'POST',
					url: 'contentapi/' + this.config.model + '/?token=1:775f9cc1168c095b1e156d8e7b58b6c4052fbdf8',
					data: objData
				}).then(

					function(result) {
						angular.forEach(self.db, function(type, field) {
							if (typeof result.data.response[field]!=='undefined') {
								self[field] = result.data.response[field];
							}
						});

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

		prototypes.Post = function Post(config) {

			if (typeof config!=='undefined') this.config = config;

			this.db = {
				"ID": "Int",
				"Title": "Varchar(255)",
				"MarkdownContent": "Text",
				"URL": "Text"
			};

			this.hasOne = {
				"Parent": "Page",
				"Image": "Image"
			};

		}

		prototypes.Post.prototype = new prototypes.DataObject();
		prototypes.Post.prototype.constructor = prototypes.Post;

		// @todo async fetch of metadata. At present, we just simulate the delay of the ajax request.
		return $timeout(function() {}, 500);
	}
}

angular.module('ssContentApi.services', [])
	.service('ssApi', ['$http', '$q', '$timeout', SSApi]);
