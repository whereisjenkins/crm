/*********************************************************************************
 * The contents of this file are subject to the Samex CRM Advanced
 * Agreement ("License") which can be viewed at
 * http://www.espocrm.com/advanced-pack-agreement.
 * By installing or using this file, You have unconditionally agreed to the
 * terms and conditions of the License, and You may not use this file except in
 * compliance with the License.  Under the terms of the license, You shall not,
 * sublicense, resell, rent, lease, distribute, or otherwise  transfer rights
 * or usage to the software.
 * 
 * License ID: bcac485dee9efd0f36cf6842ad5b69b4
 ***********************************************************************************/

Core.define('advanced:acl/quote-item', 'acl', function (Dep) {

    return Dep.extend({

        checkIsOwner: function (model) {
            if (model.has('quoteId')) {
                return true;
            } else {
                return Dep.prototype.checkIsOwner.call(this, model);
            }
        },

        checkInTeam: function (model) {
            if (model.has('quoteId')) {
                return true;
            } else {
                return Dep.prototype.checkInTeam.call(this, model);
            }
        }
    });


});