

Core.define('views/scheduled-job/record/list', 'views/record/list', function (Dep) {

    return Dep.extend({

    	quickDetailDisabled: true,

        quickEditDisabled: true,

        massActionList: ['remove', 'massUpdate']

    });

});

