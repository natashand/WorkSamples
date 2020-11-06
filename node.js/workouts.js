var express = require('express');
var router = express.Router();
var faker = require('Faker');
var Workout = require('../models').Workout;
var Input = require('../models').Input;
var Exercise = require('../models').Exercise;
var Program = require('../models').Program;
var ExerciseFields = require('../models').ExerciseFields;
var Tree = require('../models').Tree;
const Sequelize = require('sequelize');
var Marker = require('../models').Marker;
var _ = require('lodash');
const Op = Sequelize.Op;
var GUnit = require('../lib/gunit');
var MUnit = require('../lib/munit');
const passport = require('passport');
var JsonFile = require('../lib/jsonFile');
const auth = require('../routes/middleware/auth');
const permissions = require('./middleware/permissons');
var knex = require('../config/knex');
var CompanyScope = require('../lib/company_scope');

router.get('/', auth, permissions, async function (req, res, next) {
    res.render('workouts/index', {});
});

router.post('/tree', auth, permissions, async function (req, res, next) {
    var accountData = req.params.accountData;
    var markersData = req.app.locals.markers;
    var roleSuperAdmin = _.find(markersData, ['huid', '@super_admin',]);

    // super admins in DB
    var idMarkers = await knex.table('markers')
        .join('markers_groups', function () {
            this.on(function () {
                this.on('markers.id', '=', 'markers_groups.Sg')
                this.andOn('markers_groups.marker', roleSuperAdmin.uid)
            })
        })
        .where('markers.Tg', 'accounts')
        .where('markers_groups.Tg', 'markers')
        .then(result => result);
    var markersIds = _.map(idMarkers, 'Sg');

    var treeDataBD = [];
    var programs = [];
    var mainFolder = {
        id: 'all_ex',
        text: 'All Workouts',
        items: [],
        children: [],
        params: [
            '@copy_user',
            '@forbidden_add',
        ]
    };
    var params = [
        '@add_folder',
        '@rename_folder',
        '@delete_folder',
        '@copy_user',
        '@paste_user',
        '@move_user',
        '@delete_user',
    ];

    let ungroupQuery = await Program.findOne({
        where: {
            isUngroup: true
        }
    }).then(program => program);

    let ungroupFolder = {
        id: ungroupQuery.id,
        text: ungroupQuery.name,
        items: [],
        params: [
            '@copy_user',
            '@forbidden_add',
        ]
    };

    let programsQuery = selectDataTable(req.app.locals, Program.tableName, Program.tableName + '.created_id', markersIds);
    programsQuery = await programsQuery.whereIn(Program.tableName +'.type', [Program.TYPE_WORKOUT, Program.TYPE_UNGROUP])
    programs = prepareArray(programsQuery, true, programs);

    let treeQuery = await Tree.findOne({
        where: {
            type: Tree.GROUP_WORKOUT_TREE,
            created_id: {
                [Op.in]: markersIds
            }
        }
    }).then(data => data);

    if (treeQuery) {
        treeDataBD = prepareArray(treeQuery.data, true, treeDataBD);
    }

    // for Organization Level
    if (accountData.permissions.role.huid !== '@super_admin') {
        let scope = accountData.account.current_scope;
        var companyScope = new CompanyScope(scope);

        let idTreeData = await companyScope.getSGroupScope(Tree.tableName);

        if (idTreeData.length) {
            let treeScopeDataBD = await Tree.findOne({
                where: {
                    type: Tree.GROUP_WORKOUT_TREE,
                    id: {[Op.in]: idTreeData}
                }
            }).then(data => data);

            if (treeScopeDataBD) {
                treeDataBD = prepareArray(treeScopeDataBD.data, false, treeDataBD)
            }
        }

        let idPrograms = await companyScope.getSGroupScope(Program.tableName);
        if (idPrograms.length) {
            let groupsScope = selectDataTable(req.app.locals, Program.tableName, Program.tableName + '.id', idPrograms);
            groupsScope = await groupsScope.where(Program.tableName + '.type', Program.TYPE_WORKOUT);
            programs = prepareArray(groupsScope, false, programs);
        }
    }

    if (treeDataBD.length) {
        // let treeFromBD = treeDataBD.data;
        let diff = _.difference(_.map(programs, 'id'), _.map(treeDataBD, 'id'));

        // добавление недостающих папок в дерево
        if (diff) {
            diff.forEach(function (item) {
                let program = _.find(programs, ['id', item]);
                let folder = {};
                if (!program.isUngroup) {
                    folder.id = program.id;
                    folder.text = program.name;
                    folder.items = [];
                    folder.params = [];
                    folder.isBase = program.createdSuperAdmin;

                    let noFolder = {
                        id: '',
                        text: 'No Folder',
                        items: [],
                        params: [
                            '@copy_user',
                            '@no_CRUD_folder'
                        ]
                    };
                    noFolder.id = program.id + '_no_folder';
                    folder.children = [];
                    folder.children.push(noFolder);

                    if (program.createdSuperAdmin) {
                        treeDataBD.unshift(folder);
                    } else {
                        treeDataBD.push(folder);
                    }
                }
            });

            treeDataBD.unshift(mainFolder);
            treeDataBD.push(ungroupFolder);
        }

    } else {
        // create the new Tree
        for (let group of programs) {
            let folder = {};
            let noFolder = {
                id: '',
                text: 'No Folder',
                items: [],
                params: [
                    '@copy_user',
                    '@no_CRUD_folder'
                ]
            };

            if (!group.isUngroup) {
                folder.id = group.id;
                folder.text = group.name;
                folder.items = [];
                folder.params = [];
                folder.isBase = group.createdSuperAdmin;

                noFolder.id = group.id + '_no_folder';
                folder.children = [];
                folder.children.push(noFolder);

                treeDataBD.push(folder);
            }
        }

        treeDataBD.push(ungroupFolder);
        treeDataBD.unshift(mainFolder);
    }

    let recursiveRename = ((element, prefix) => {
        if (_.isEmpty(element.children)) {
            return element.text = prefix + element.text;
        } else {
            element.children.map((elem) => {
                return recursiveRename(elem, prefix);
            });
        }
    });

    for (let i = 1; i < treeDataBD.length - 1; i++) {
        if (treeDataBD[i].isBase && accountData.permissions.role.huid !== '@super_admin') {
            treeDataBD[i].params = [
                '@copy_user',
                '@no_CRUD_folder'
            ];
            recursiveRename(treeDataBD[i], 'Base: ');
            treeDataBD[i].text = 'Base: ' + treeDataBD[i].text;
        } else if (!treeDataBD[i].isBase && accountData.permissions.role.huid !== '@super_admin') {
            treeDataBD[i].params = params;
            recursiveRename(treeDataBD[i], 'Org: ');
            treeDataBD[i].text = 'Org: ' + treeDataBD[i].text;
        } else {
            treeDataBD[i].params = params;
        }
    }

    res.status(201).send({treeData: treeDataBD});
});

router.post('/list/:id', auth, permissions, async function (req, res, next) {
    const getId = parseInt(req.params.id);
    let accountData = req.params.accountData;
    let userMarkers = accountData.permissions.markers;
    var markersData = req.app.locals.markers;

    //super admins in DB
    let roleSuperAdmin = _.find(markersData, ['huid', '@super_admin',]);
    var idMarkers = await knex.table('markers')
        .join('markers_groups', function () {
            this.on(function () {
                this.on('markers.id', '=', 'markers_groups.Sg')
                this.andOn('markers_groups.marker', roleSuperAdmin.uid)
            })
        })
        .where('markers.Tg', 'accounts')
        .where('markers_groups.Tg', 'markers')
        .then(result => result);
    let markers = [];
    let markersIds = _.map(idMarkers, 'Sg');

    for (let id of userMarkers) {
        let marker = _.find(markersData, ['uid', id]);
        if (marker) {
            markers.push(marker.huid);
        }
    }

    // select Programs
    var programs = selectDataTable(req.app.locals, Program.tableName, 'created_id', markersIds);
    programs = await programs.whereIn(Program.tableName + '.type', [Program.TYPE_WORKOUT, Program.TYPE_UNGROUP]);

    if (accountData.permissions.role.huid !== '@super_admin') {
        var scope = accountData.account.current_scope;
        var companyScope = new CompanyScope(scope);

        let idPrograms = await companyScope.getSGroupScope(Program.tableName);
        if (idPrograms.length) {
            let groupsScope = selectDataTable(req.app.locals, Program.tableName, Program.tableName + '.id', idPrograms);
            groupsScope = await groupsScope.where(Program.tableName + '.type', Program.TYPE_WORKOUT);
            programs = prepareArray(groupsScope, false, programs);
        }
    }

    var workouts = [];

    // select workouts by the Program
    if (getId) {
        var choicePrograms = await _.find(programs, ['id', getId]);
        let childrenWO = [];
        if (_.has(choicePrograms, 'group_workout') && +choicePrograms.group_workout > 0) {
            var gunit = new GUnit(choicePrograms, Program.tableName, 'group_workout', Workout.tableName);
            childrenWO = await gunit.getChildren();
        }
        workouts = await selectDataTable(req.app.locals, Workout.tableName, Workout.tableName + '.id', childrenWO, true, true);
    } else {
        var query = await selectDataTable(req.app.locals, Workout.tableName, Workout.tableName + '.created_id', markersIds, true, true);
        workouts = prepareArray(query, true, workouts);

        if (accountData.permissions.role.huid !== '@super_admin') {
            let IdWorkouts = await companyScope.getSGroupScope(Workout.tableName);
            if (IdWorkouts.length) {
                let groupsScope = await selectDataTable(req.app.locals, Workout.tableName, Workout.tableName + '.id', IdWorkouts, true, true);
                workouts = prepareArray(groupsScope, false, workouts);
            }
        }
    }

    for (let wo of workouts) {
        let ungroup = _.find(programs, ['isUngroup', 1]);
        let getParent = await GUnit.getAllParents(Program.tableName, Workout.tableName, wo.id);
        let findProgram = _.find(programs, ['group_workout', getParent[0]]);
        wo.program = findProgram ? findProgram : ungroup;
    }

    await workouts.map(function (item) {
        if (_.has(item, 'role')) {
            item.role = _.find(markersData, ['uid', item.role]);
        }
        if (item.exercises) {
            item.exercises = JSON.parse(item.exercises);
        }
    });

    res.status(201).send({workouts: workouts, programs: programs, markers: markers});
});

router.post('/save', auth, permissions, async function (req, res) {
    let accountData = req.params.accountData;
    let userRole = accountData.permissions.role.huid;

    const {data} = req.body;

    // validation
    // .................

    // Type definition
    var type;
    if (userRole === '@super_admin') {
        type = Workout.TYPE_BASE;
    } else if (userRole === '@facility_admin' || userRole === '@chief_facility_trainer') {
        type = data.type;
    } else {
        type = Workout.TYPE_ORDINARY;
    }

    // work with group
    let programModel;
    if (data.program) {
        programModel = await Program.findByPk(data.program).then(entity => entity);
    } else {
        // error group not Found
        return next({statusCode: 404, error: true, message: 'Program not Found'});
    }

    // create
    var object = {
        name: data.name,
        description: data.description,
        type: type,
        notes: data.notes,
        is_published: data.is_published,
        exercises: data.exercises,
        created_id: accountData.permissions.id
    };

    Workout.create(object).then(workout => {

        // create object GUnit (tsc)
        var gunit = new GUnit(programModel, Program.tableName, 'group_workout', Workout.tableName);
        gunit.addChild(workout);

        if (accountData.permissions.role.huid !== '@super_admin') {
            var companyScope = new CompanyScope(accountData.account.current_scope);
            companyScope.addToScope(Workout.tableName, workout.id);
        }

        res.status(201).json({success: true});
    }).catch(err => {
        return next({statusCode: 404, error: true, message: 'Not Found'});
    });
});

router.post('/update', auth, permissions, async function (req, res) {
    let accountData = req.params.accountData;
    let userRole = accountData.permissions.role.huid;
    const {data} = req.body;

    // validation
    // .................

    // Type definition
    var type;
    if (userRole === '@super_admin') {
        type = Workout.TYPE_BASE;
    } else if (userRole === '@facility_admin' || userRole === '@chief_facility_trainer') {
        type = data.type;
    } else {
        type = Workout.TYPE_ORDINARY;
    }

    // work with group
    let programModel;
    if (data.program) {
        programModel = await Program.findByPk(data.program).then(entity => entity);
    } else {
        // error group not Found
        return next({statusCode: 404, error: true, message: 'Group not Found'});
    }

    // create
    var object = {
        name: data.name,
        description: data.description,
        type: type,
        notes: data.notes,
        is_published: data.is_published,
        exercises: data.exercises,
        created_id: accountData.permissions.id
    };

    var workoutUpdate = await Workout.findByPk(data.id).then(workout => {
        return workout;
    });
    Workout.update(object, {
        where: {
            id: data.id
        }
    }).then(workout => {
        // create object GUnit (tsc)
        var gunit = new GUnit(programModel, Program.tableName, 'group_workout', Workout.tableName);
        gunit.addChild(workoutUpdate);

        res.status(201).json({success: true});
    }).catch(err => {
        return next({statusCode: 404, error: true, message: 'Not Found'});
    });
});

router.get('/view/:id', auth, async function (req, res) {
    let id = req.params.id;
    var workout = await Workout.findByPk(id).then(workout => {
        return workout;
    });

    res.status(200).send({workout: workout});
});

router.post('/delete', auth, permissions, async function (req, res) {
    let currentUser = req.params.accountData;
    const {data} = req.body;
    // validation
    // .................
    // work with group
    let programModel;
    if (data.program) {
        programModel = await Program.findByPk(data.program).then(entity => entity);
    } else {
        // error group not Found
        return next({statusCode: 404, error: true, message: 'Program not Found'});
    }

    var workoutDelete = await Workout.findByPk(data.id).then(workout => {
        return workout;
    });
    // create object GUnit (tsc)
    var gunit = new GUnit(programModel, Program.tableName, 'group_workout', Workout.tableName);
    gunit.destroyChild(workoutDelete);

    if (currentUser.permissions.role.huid !== '@super_admin') {
        var companyScope = new CompanyScope(currentUser.account.current_scope);
        await companyScope.deleteFromScope(Workout.tableName, workoutDelete.id);
    }

    workoutDelete.destroy();

    res.status(201).send({success: true});
});

router.post('/save-tree', auth, permissions, async function (req, res) {
    let currentUser = req.params.accountData;
    let markersData = req.app.locals.markers;
    let roleSuperAdmin = _.find(markersData, ['huid', '@super_admin',]);
    var idMarkers = await knex.table('markers')
        .join('markers_groups', function () {
            this.on(function () {
                this.on('markers.id', '=', 'markers_groups.Sg')
                this.andOn('markers_groups.marker', roleSuperAdmin.uid)
            })
        })
        .where('markers.Tg', 'accounts')
        .where('markers_groups.Tg', 'markers')
        .then(result => result);
    let markersIds = _.map(idMarkers, 'Sg');

    const {tree} = req.body;
    tree.splice(0, 1);

    let dataTree;
    let treeData = {
        type: Tree.GROUP_WORKOUT_TREE,
        data: null,
        created_id: currentUser.permissions.id
    };

    if (currentUser.permissions.role.huid === '@super_admin') {
        dataTree = _.filter(tree, ['isBase', true]);
        treeData.data = dataTree;

        let treeBD = await Tree.findOne({
            where: {
                type: Tree.GROUP_WORKOUT_TREE,
                created_id: {
                    [Op.in]: markersIds
                }
            }
        }).then(tree => tree);

        if (treeBD) {
            await Tree.update(treeData, {
                where: {
                    id: treeBD.id
                }
            }).then(entity => entity).catch(err => {
                return next({statusCode: 404, error: true, message: 'Incorrect params'});
            });
        } else {
            await Tree.create(treeData).then(entity => entity).catch(err => {
                console.log(err)
            });
        }

    } else {
        let scope = currentUser.account.current_scope;
        var companyScope = new CompanyScope(scope);
        dataTree = _.filter(tree, ['isBase', false]);
        treeData.data = dataTree;

        let idTreeData = await companyScope.getSGroupScope(Tree.tableName);

        if (idTreeData.length) {
            var treeBD = await Tree.findOne({
                where: {
                    type: Tree.GROUP_WORKOUT_TREE,
                    id: {[Op.in]: idTreeData}
                }
            }).then(data => data);
        }

        if (treeBD) {
            await Tree.update(treeData, {
                where: {
                    id: treeBD.id
                }
            }).then(entity => entity).catch(err => {
                return next({statusCode: 404, error: true, message: 'Incorrect params'});
            });
        } else {
            await Tree.create(treeData).then(entity => {
                if (currentUser.permissions.role.huid !== '@super_admin') {
                    var companyScope = new CompanyScope(currentUser.account.current_scope);
                    companyScope.addToScope(Tree.tableName, entity.id);
                }
                return entity
            }).catch(err => {
                console.log(err)
            });
        }
    }

    res.status(201).send({success: true});
});

function prepareArray(array, isSuperAdmin, newArray) {
    array.map((item) => {
        item.createdSuperAdmin = isSuperAdmin;
        newArray.push(item);
    });

    return newArray;
}

function selectDataTable(locals, tableName, columnName, arrayIds, unGroup = true, join = false) {
    const munit = new MUnit(locals);
    let roleIdS = munit.getAllRoleUid();

    let query = knex.table(tableName).select(tableName + '.*')
        .whereIn(columnName, arrayIds);
    if (!unGroup) {
        query.where('isUngroup', false)
    }
    if (join) {
        query.select('markers_groups.marker as role', 'accounts.firstName', 'accounts.lastName')
            .join('markers', 'markers.id', '=', tableName + '.created_id')
            .join('accounts', 'markers.Sg', '=', 'accounts.id')
            .join('markers_groups', 'markers_groups.Sg', '=', 'markers.id')
            .whereIn('markers_groups.marker', roleIdS)
            .where('markers_groups.Tg', 'markers')
            .where('markers.Tg', 'accounts').then(item => item);
    }

    return query;
}

module.exports = router;
