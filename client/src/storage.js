

Core.define('storage', [], function () {

    var Storage = function () {
    };

    _.extend(Storage.prototype, {

        _prefix: 'espo',

        storageObject: localStorage,

        _composeFullPrefix: function (type) {
            return this._prefix + '-' + type;
        },

        _composeKey: function (type, name) {
            return this._composeFullPrefix(type) + '-' + name;
        },

        _checkType: function (type) {
            if (typeof type === 'undefined' && toString.call(type) != '[object String]' || type == 'cache') {
                throw new TypeError("Bad type \"" + type + "\" passed to Core.Storage.");
            }
        },

        get: function (type, name) {
            this._checkType(type);

            var key = this._composeKey(type, name);

            var stored = this.storageObject.getItem(key);
            if (stored) {
                var str = stored;
                if (stored[0] == "{" || stored[0] == "[") {
                    try {
                        str = JSON.parse(stored);
                    } catch (error) {
                        str = stored;
                    }
                    stored = str;
                }
                return stored;
            }
            return null;
        },

        set: function (type, name, value) {
            this._checkType(type);

            var key = this._composeKey(type, name);
            if (value instanceof Object) {
                value = JSON.stringify(value);
            }
            this.storageObject.setItem(key, value);
        },

        clear: function (type, name) {
            var reText;
            if (typeof type !== 'undefined') {
                if (typeof name === 'undefined') {
                    reText = '^' + this._composeFullPrefix(type);
                } else {
                    reText = '^' + this._composeKey(type, name);
                }
            } else {
                reText = '^' + this._prefix + '-';
            }
            var re = new RegExp(reText);
            for (var i in this.storageObject) {
                if (re.test(i)) {
                    delete this.storageObject[i];
                }
            }
        }
    });

    Storage.extend = Backbone.Router.extend;

    return Storage;
});
