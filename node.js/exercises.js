var express = require('express');
var router = express.Router();
var faker = require('Faker');
var Input = require('../models').Input;
var Exercise = require('../models').Exercise;
var ExerciseGroups = require('../models').ExerciseGroups;
var Tree = require('../models').Tree;
const Sequelize = require('sequelize');
const Op = Sequelize.Op;
var GUnit = require('../lib/gunit');
var MUnit = require('../lib/munit');
var _ = require('lodash');
const passport = require('passport');
var JsonFile = require('../lib/jsonFile');
const auth = require('../routes/middleware/auth');
const permissions = require('./middleware/permissons');
var Marker = require('../models').Marker;
var knex = require('../config/knex');
var CompanyScope = require('../lib/company_scope');

router.get('/get_all', passport.authenticate('jwt', {session: false}), function (req, res, next) {
    Exercise.findAll().then(exercises => {
        return res.json({
            success: true,
            message: 'You have access, token is working! This will be Exercises',
            exercises
        });
    })
});

router.get('/', auth, permissions, async function (req, res, next) {
    res.render('exercises/index');
});

router.post('/tree', auth, permissions, async function (req, res, next) {
    var accountData = req.params.accountData;
    var markersData = req.app.locals.markers;
    var roleSuperAdmin = _.find(markersData, ['huid', '@super_admin',]);
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
    var groups = [];
    var mainFolder = {
        id: 'all_ex',
        text: 'All Exercises',
        items: [],
        children: [],
        params: [
            '@copy_user',
            '@forbidden_add',
        ],
        isBase: true
    };
    var params = [
        '@add_folder',
        '@rename_folder',
        '@delete_folder',
        '@copy_user',
        '@move_user',
        '@delete_user',
    ];

    let ungroupQuery = await ExerciseGroups.findOne({
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

    let exersisesQuery = await selectDataTable(req.app.locals, ExerciseGroups.tableName, ExerciseGroups.tableName + '.created_id', markersIds);
    groups = prepareArray(exersisesQuery, true, groups);

    let treeQuery = await Tree.findOne({
        where: {
            type: Tree.GROUP_EXERCISES_TREE,
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
                    type: Tree.GROUP_EXERCISES_TREE,
                    id: {[Op.in]: idTreeData}
                }
            }).then(data => data);

            if (treeScopeDataBD) {
                treeDataBD = prepareArray(treeScopeDataBD.data, false, treeDataBD)
            }
        }

        let idGroups = await companyScope.getSGroupScope(ExerciseGroups.tableName);
        if (idGroups.length) {
            let groupsScope = await selectDataTable(req.app.locals, ExerciseGroups.tableName, ExerciseGroups.tableName + '.id', idGroups);
            groups = prepareArray(groupsScope, false, groups);
        }
    }

    if (treeDataBD.length) {
        // let treeFromBD = treeDataBD.data;
        let diff = _.difference(_.map(groups, 'id'), _.map(treeDataBD, 'id'));

        // добавление недостающих папок в дерево
        if (diff) {
            diff.forEach(function (item) {
                let program = _.find(groups, ['id', item]);
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
        for (let group of groups) {
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

    let markers = [];
    for (let id of userMarkers) {
        let marker = _.find(markersData, ['uid', id]);
        if (marker) {
            markers.push(marker.huid);
        }
    }
    let markersIds = _.map(idMarkers, 'Sg');

    // Select ExervciseGroups
    var groups = await selectDataTable(req.app.locals, ExerciseGroups.tableName, 'created_id', markersIds);

    if (accountData.permissions.role.huid !== '@super_admin') {
        var scope = accountData.account.current_scope;
        var companyScope = new CompanyScope(scope);

        let idExGroups = await companyScope.getSGroupScope(ExerciseGroups.tableName);
        if (idExGroups.length) {
            let groupsScope = await selectDataTable(req.app.locals, ExerciseGroups.tableName, 'id', idExGroups);
            groups = prepareArray(groupsScope, false, groups);
        }
    }

    var exercises = [];
    let assignedExGroup = [];

    if (getId) {
        var choiseGroup = await _.find(groups, ['id', getId]);

        let childrenExercise = [];

        if (choiseGroup.group_exercise) {
            var gunit = new GUnit(choiseGroup, ExerciseGroups.tableName, 'group_exercise', Exercise.tableName);
            childrenExercise = await gunit.getChildren();
        }
        exercises = await selectDataTable(req.app.locals, Exercise.tableName, Exercise.tableName + '.id', childrenExercise, true, true);

    } else {
        var exercisesQuery = await selectDataTable(req.app.locals, Exercise.tableName, Exercise.tableName + '.created_id', markersIds, true, true);
        exercises = prepareArray(exercisesQuery, true, exercises);

        if (accountData.permissions.role.huid !== '@super_admin') {
            let IdExercises = await companyScope.getSGroupScope(Exercise.tableName);
            if (IdExercises.length) {
                let groupsScope = await selectDataTable(req.app.locals, Exercise.tableName, Exercise.tableName + '.id', IdExercises, true, true);
                exercises = prepareArray(groupsScope, false, exercises);
            }
        }
    }

    for (let item of exercises) {
        let ungroup = _.find(groups, ['isUngroup', 1]);
        let allParent = await GUnit.getAllParents(ExerciseGroups.tableName, Exercise.tableName, item.id);
        let findGroup = _.find(groups, ['group_exercise', allParent[0]]);
        item.group = findGroup ? findGroup : ungroup;
    }

    await exercises.forEach(function (item) {
        if (_.has(item, 'role')) {
            item.role = _.find(markersData, ['uid', item.role]).title;
        }

        if (item.parent_id) {
            item.parent_id = Exercise.findByPk(item.parent_id).then(object => object);
        }
    });

    res.status(201).send({exercises: exercises, groups: groups, exGroup: assignedExGroup, markers: markers});
});

router.post('/save', auth, permissions, async function (req, res) {
    let accountData = req.params.accountData;
    let userRole = accountData.permissions.role.huid;

    const {data} = req.body;

    // Type definition
    var type;
    if (userRole === '@super_admin') {
        type = Exercise.TYPE_BASE;
    } else if (userRole === '@facility_admin' || userRole === '@chief_facility_trainer') {
        type = data.type;
    } else {
        type = Exercise.TYPE_ORDINARY;
    }

    // work with group
    let groupModel;
    if (data.group) {
        groupModel = await ExerciseGroups.findByPk(data.group).then(entity => {
            return entity
        }).catch(err => {
            return next({statusCode: 404, error: true, message: 'Group not Found'});
        });
    }

    // create
    var object = {
        name: data.name,
        description: data.description,
        link: data.link,
        type: type,
        is_published: data.is_published,
        parent_id: data.parent_id,
        created_id: accountData.permissions.id
    };

    Exercise.create(object).then(exercise => {
        // create object GUnit (tsc)
        var gunit = new GUnit(groupModel, ExerciseGroups.tableName, 'group_exercise', Exercise.tableName);
        gunit.addChild(exercise);

        if (accountData.permissions.role.huid !== '@super_admin') {
            var companyScope = new CompanyScope(accountData.account.current_scope);
            companyScope.addToScope(Exercise.tableName, exercise.id);
        }
        return exercise;

    }).catch(err => {
        return next({statusCode: 404, error: true, message: 'Not Found'});
    });

    res.status(201).send({success: true});
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
        type = Exercise.TYPE_BASE;
    } else if (userRole === '@facility_admin' || userRole === '@chief_facility_trainer') {
        type = data.type;
    } else {
        type = Exercise.TYPE_ORDINARY;
    }


    // work with group
    let groupModel;
    if (data.group) {
        groupModel = await ExerciseGroups.findByPk(data.group).then(entity => entity);
    } else {
        // error group not Found
        return next({statusCode: 404, error: true, message: 'Group not Found'});
    }

    // create
    var object = {
        name: data.name,
        description: data.description,
        link: data.link,
        type: type,
        is_published: data.is_published,
        parent_id: data.parent_id ? data.parent_id.id : data.parent_id,
        created_id: accountData.permissions.id
    };

    var exerciseUpdate = await Exercise.findByPk(data.id).then(exercise => {
        return exercise;
    });
    Exercise.update(object, {
        where: {
            id: data.id
        }
    }).then(exercise => {
        // create object GUnit (tsc)
        var gunit = new GUnit(groupModel, ExerciseGroups.tableName, 'group_exercise', Exercise.tableName);
        gunit.addChild(exerciseUpdate);

    });
    res.status(201).send({success: true});
});

router.post('/delete', auth, permissions, async function (req, res) {
    let currentUser = req.params.accountData;
    const {data} = req.body;
    // validation
    // .................
    // work with group
    let groupModel;
    if (data.group) {
        groupModel = await ExerciseGroups.findByPk(data.group).then(entity => entity);
    } else {
        // error group not Found
        return next({statusCode: 404, error: true, message: 'Group not Found'});
    }

    var exerciseDelete = await Exercise.findByPk(data.id).then(exercise => {
        return exercise;
    });

    var prototips = await Exercise.findAll({
        where: {
            parent_id: data.id
        }
    }).then(exercise => {
        return exercise;
    });

    if (prototips.length) {
        for (let item of prototips) {
            if (currentUser.permissions.role.huid !== '@super_admin') {
                var scope = new CompanyScope(currentUser.account.current_scope);
                await scope.deleteFromScope(ExerciseFields.tableName, item.id);
            }
            item.destroy();
        }
    }
    // create object GUnit (tsc)
    var gunit = new GUnit(groupModel, ExerciseGroups.tableName, 'group_exercise', Exercise.tableName);
    gunit.destroyChild(exerciseDelete);

    if (currentUser.permissions.role.huid !== '@super_admin') {
        var companyScope = new CompanyScope(currentUser.account.current_scope);
        await companyScope.deleteFromScope(ExerciseFields.tableName, exerciseDelete.id);
    }

    exerciseDelete.destroy();

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
        type: Tree.GROUP_EXERCISES_TREE,
        data: null,
        created_id: currentUser.permissions.id
    };

    if (currentUser.permissions.role.huid === '@super_admin') {
        dataTree = _.filter(tree, ['isBase', true]);
        treeData.data = dataTree;


        let treeBD = await Tree.findOne({
            where: {
                type: Tree.GROUP_EXERCISES_TREE,
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

        console.log('treData', treeData);

        let idTreeData = await companyScope.getSGroupScope(Tree.tableName);

        if (idTreeData.length) {
            var scopeTreeBD = await Tree.findOne({
                where: {
                    type: Tree.GROUP_EXERCISES_TREE,
                    id: {[Op.in]: idTreeData}
                }
            }).then(data => data);
        }

        if (scopeTreeBD) {
            await Tree.update(treeData, {
                where: {
                    id: scopeTreeBD.id
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

router.get('/view/:id', async function (req, res) {
    let id = req.params.id;
    var exercise = await Exercise.findByPk(id).then(exercise => {
        return exercise;
    });

    res.status(200).send({exercise: exercise});
});

router.get('/ginput/save/:id', async function (req, res) {
    let id = req.params.id;
    if (id === undefined) {
        throw undefined;
    }

    let Tg = 'exercises';
    let Cg = 'group_input';
    let T = 'inputs';

    let inputIds = [3];

    var entity = await Exercise.findByPk(id).then(entity => {
        return entity;
    });
    if (!entity) {
        // not found
        throw undefined;
    }

    let units = await Input.findAll({
        where: {
            id: {
                [Op.in]: inputIds
            }
        }
    });

    var gunit = new GUnit(entity, Tg, Cg, T);
    gunit.addChildren(units);

    res.status(201).send('added exercise ginput');
});

router.use(function (err, req, res, next) {
    res.status(err.statusCode || 500).json(err);
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
        query.select( 'markers_groups.marker as role', 'accounts.firstName', 'accounts.lastName')
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
