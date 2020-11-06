<template>
    <div class="row">
        <div class="col-md-3">
            <div class="ibox-content" v-if="showTree">
                <h3>Exercises Tree</h3>

                <group-tree :props-tree="tree"
                            :disabled="isDisabledTree"
                            :can-create-root-folder="false"
                            @changeTree="onChangeTree"
                            @selectFolder="onSelectedFolder"
                ></group-tree>
            </div>
            <div class="ibox-content" v-else>
                <p class="text-center">Loading...</p>
            </div>
        </div>
        <div class="col-md-9">
            <div class="ibox-content">
                <div>
                    <h2 class="m-t-none">{{ breadcrumbs }}</h2>
                    <button v-if="userCan('@create_exercise')" class="btn btn-primary m-t-sm"
                            @click="openModal('Create')">Create
                        Exercise
                    </button>

                    <modal-form-exercise v-if="showModal"
                                         :prop-is-action="action"
                                         :prop-data="editData"
                                         @close="close"
                    ></modal-form-exercise>

                    <table class="footable table table-stripped toggle-arrow-tiny" ref="footable">
                        <thead>
                        <tr>
                            <th>
                                <checkbox :checked="allExChecked"
                                          :data-role="'All_Exercises_Checked'"
                                          :disabled="false"
                                          @change="onAllExerciseSelect"
                                ></checkbox>
                            </th>
                            <th data-toggle="true">Exercise Name</th>
                            <th>Exercise Group</th>
                            <th v-if="isAutorAdmin">Exercise type</th>
                            <th>Assigned</th>
                            <th>Last Modified</th>
                            <th>Short description</th>
                            <th data-hide="all">Link</th>
                            <th data-hide="all">Full&nbsp;Description</th>
                            <th v-if="isAutorAdmin" data-hide="all">Created By</th>
                            <th data-hide="all"></th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr v-for="item in selectExercises">
                            <td>
                                <checkbox :checked="isCheckedExercise(item)"
                                          :id="item"
                                          :data-role="selectFolder.id + '_' + item.id"
                                          :disabled="false"
                                          @change="onCheckedExercises"
                                ></checkbox>
                            </td>
                            <td>{{ item.name }}</td>
                            <td>
                                <span class="label label-default"
                                      :style="'background-color:' + item.group.color"
                                >
                                    {{ item.group.name }}
                                </span>
                            </td>
                            <td v-if="isAutorAdmin">
                                {{ getTypeStr(item.type) }}
                                <sup v-if="item.tooltip"><span class="fa fa-info" data-toggle="tooltip"
                                                               :title="'Fields were changed by ' + item.role"></span></sup>
                            </td>
                            <td>{{ item.assigned }}</td>
                            <td>{{ getDataTime(item.updatedAt) }}</td>
                            <td>{{ shortDescription(item.description) }}</td>
                            <td>
                                <span v-if="item.localLink">
                                    <a :href="item.localLink" target="_blank">{{ item.localLink }}</a>
                                </span>
                                <span v-else>
                                    <a v-if="item.link" :href="item.link" target="_blank">{{ item.link }}</a>
                                </span>
                            </td>
                            <td>
                                <span v-if="item.localDescription">
                                    {{ item.localDescription }}
                                </span>
                                <span v-else>
                                    {{ item.description }}
                                </span>
                            </td>
                            <td v-if="isAutorAdmin">{{ item.firstName + ' ' + item.lastName }}</td>
                            <td v-if="!userCan('@athlete')">
                                <button @click="openModal('Edit', item.id)" v-if="userCan('@update_exercise')"
                                        class="btn btn-success btn-sm m-t-xs">Edit
                                </button>
                                <button @click="openModal('Copy', item.id)"
                                        v-if="userCan('@create_exercise')"
                                        class="btn btn-info btn-sm m-t-xs">Copy
                                </button>
                                <button @click="doDelete(item.id)"
                                        v-if="userCan('@delete_exercise') && canDelete(item.createdSuperAdmin)"
                                        class="btn btn-danger btn-sm m-t-xs">Delete
                                </button>
                            </td>
                        </tr>
                        </tbody>
                        <tfoot>
                        <tr>
                            <td colspan="5">
                                <ul class="pagination float-right"></ul>
                            </td>
                        </tr>
                        </tfoot>
                    </table>
                    <div class="pb-20">
                        <div class="col-md-12" style="display: flex">
                            <div class="col-md-3">
                                <button class="btn btn-sm btn-info btn-block"
                                        :disabled="isCopyButtonDisabled"
                                        @click="onCopyClick"
                                ><i class="fa fa-copy"></i> Copy
                                </button>
                            </div>
                            <div class="col-md-3">
                                <button class="btn btn-sm btn-primary btn-block"
                                        :disabled="isPasteButtonDisabled"
                                        @click="onPasteClick"
                                ><i class="fa fa-paste"></i> Paste
                                </button>
                            </div>
                            <div class="col-md-3">
                                <button class="btn btn-sm btn-warning btn-block"
                                        :disabled="isMoveButtonDisabled"
                                        @click="onMoveClick"
                                ><i class="fa fa-scissors"></i> Move
                                </button>
                            </div>
                            <div class="col-md-3">
                                <button class="btn btn-sm btn-danger btn-block"
                                        :disabled="isDeleteButtonDisabled"
                                        @click="onDeleteClick"
                                ><i class="fa fa-trash"></i> Delete
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <!--                <div v-if="isLoaded">-->
                <!--                    <p class="text-center">Loading...</p>-->
                <!--                </div>-->
            </div>
        </div>
    </div>
</template>

<script>
    import axios from 'axios';
    import GroupTree from '../GroupTree.vue';
    import Checkbox from '../Checkbox.vue';
    import ModalFormExercise from './ModalFormExercise.vue';

    export default {
        name: "ExercisesList",

        components: {
            GroupTree,
            Checkbox,
            ModalFormExercise
        },
        data: function () {
            return {
                isLoaded: false,
                action: '',
                showModal: false,

                modalData: {
                    exId: 0,
                    type: 0,
                    group: 0,
                    name: '',
                    link: '',
                    localLink: '',
                    description: '',
                    localDescription: '',
                    isPublished: 0,
                    parentId: 0,

                    allowLocalLink: 0,
                    allowLocalDescription: 0
                },
                modalErrors: [],

                allExercises: null,
                exercises: null,
                groups: null,
                markers: null,

                tree: null,
                showTree: 0,
                selectFolderNames: [],
                permissions: [],
                selectFolder: {
                    items: []
                },

                allExChecked: false,

                checkedExercises: [],
                copyBuffer: [],
                isCopyOrMoveClicked: false,
                isPasteButton: false,
                isBusy: false,
                exGroup: null,
                editData: {
                    type: 1,
                    group: 0,
                    name: '',
                    link: '',
                    localLink: '',
                    description: '',
                    localDescription: '',
                    isPublished: 0,
                    parent_id: null,

                    allowLocalLink: 0,
                    allowLocalDescription: 0,
                    saveUrl: null
                },
                newExercise: {
                    type: 1,
                    group: 0,
                    name: '',
                    link: '',
                    localLink: '',
                    description: '',
                    localDescription: '',
                    isPublished: 0,
                    parent_id: null,

                    allowLocalLink: 0,
                    allowLocalDescription: 0,
                    saveUrl: null
                },
                typeName: {
                    0: 'Base',
                    1: 'Org. Base',
                    2: 'Ordinary'
                }
            }
        },
        mounted: function () {
            this.reload();
        },
        computed: {
            getDataTime() {
                return (date) => {
                    var d = new Date(date);
                    var day = d.getDate();
                    var month = d.getMonth() + 1;
                    var year = d.getFullYear();
                    return month + '/' + day + '/' + year;
                }
            },

            shortDescription() {
                return (description) => {
                    let arr = [];
                    if (description) {
                        arr = description.split(' ');
                    }
                    let short = [];
                    for (let i = 0; i < 7; i++) {
                        short.push(arr[i]);
                    }
                    return short.join(' ');
                }
            },

            isAutorAdmin() {
                return this.userCan('@facility_admin') || this.userCan('@chief_facility_trainer');
            },

            canDelete() {
                return (createdSuperAdmin) => {
                    if (this.isAutorAdmin && !createdSuperAdmin) {
                        return true;
                    }
                    if (!this.isAutorAdmin && createdSuperAdmin) {
                        return true
                    }
                    return false;
                }
            },

            breadcrumbs() {
                return this.selectFolderNames.join(' / ');
            },

            isCheckedExercise() {
                return (item) => {
                    let index = _.findIndex(this.checkedExercises, (n) => {
                        return n === parseInt(item.id)
                    });

                    return index >= 0;
                };
            },

            userCan() {
                return (marker) => {
                    return _.includes(this.markers, marker);
                }
            },

            isDisabledTree() {
                if (this.selectFolder) {
                    return _.includes(this.selectFolder.params, '@no_CRUD_folder');
                }
                return true;
            },

            isCopyButtonDisabled() {
                if (_.isEmpty(this.checkedExercises)) {
                    return true;
                }

                if (_.isEmpty(this.selectFolder)) {
                    return true;
                }

                if (this.isCopyOrMoveClicked) {
                    return true;
                }

                return !_.includes(this.selectFolder.params, '@copy_user');
            },

            isMoveButtonDisabled() {
                if (_.isEmpty(this.checkedExercises)) {
                    return true;
                }

                if (_.isEmpty(this.selectFolder)) {
                    return true;
                }

                if (this.isCopyOrMoveClicked) {
                    return true;
                }

                return !_.includes(this.selectFolder.params, '@move_user');
            },

            isDeleteButtonDisabled() {
                if (_.isEmpty(this.checkedExercises)) {
                    return true;
                }

                if (_.isEmpty(this.selectFolder)) {
                    return true;
                }

                if (this.isCopyOrMoveClicked) {
                    return true;
                }

                return !_.includes(this.selectFolder.params, '@delete_user');
            },

            isPasteButtonDisabled() {
                if (_.isEmpty(this.selectFolder)) {
                    return true;
                }

                if (this.isPasteButton) {
                    return true;
                }

                return !_.includes(this.selectFolder.params, '@paste_user');
            },

            selectExercises() {
                let select = [];
                if (!_.isEmpty(this.selectFolder.items)) {
                    this.selectFolder.items.forEach((el) => {
                        let byId = this.getById(el);
                        if (!_.isEmpty(byId)) {
                            return select.push(byId);
                        }
                    });
                }
                this.init();
                return select;
            }
        },

        methods: {
            init() {
                setTimeout(() => {
                    $(this.$refs.footable).trigger('footable_redraw');
                }, 10);
            },

            openModal(action, id = 0) {
                this.showModal = true;
                this.action = action;
                if (id) {
                    this.editData = _.find(this.allExercises, ['id', id]);
                    var ed = this.editData;
                    ed.group = ed.group.id;

                    if (action === 'Copy') {
                        if (this.isAutorAdmin && ed.type === 0) {
                            ed.type = 1;
                        }
                        ed.name = ed.name + ' (copy)';
                        ed.saveUrl = '/exercise/save';
                    } else if (action === 'Edit' && this.isAutorAdmin && ed.type === 0) {
                        if (ed.parent_id) {
                            ed.localDescription = ed.description;
                            ed.description = ed.parent_id.description;
                            ed.localLink = ed.link;
                            ed.link = ed.parent_id.link;
                            ed.saveUrl = '/exercise/update';
                        } else {
                            ed.parent_id = id;
                            ed.saveUrl = '/exercise/save';
                        }
                        ed.allowLocalLink = 0;
                        ed.allowLocalDescription = 0;
                    } else {
                        ed.saveUrl = '/exercise/update';
                    }
                } else {
                    this.editData = _.cloneDeep(this.newExercise);
                    console.log('selectFolder', parseInt(this.selectFolder.id));
                    if (!_.isEmpty(this.selectFolder) && parseInt(this.selectFolder.id)) {
                        this.editData.group = parseInt(this.selectFolder.id);
                    } else {
                        this.editData.group = _.find(this.groups, ['isUngroup', 1]).id;
                    }
                    this.editData.saveUrl = '/exercise/save';
                }
            },
            close() {
                this.showModal = false;
            },
            reload: function () {
                this.isLoaded = false;
                const self = this;

                axios.post('/exercise/tree')
                    .then((response) => {
                        this.tree = response.data.treeData;
                        this.showTree = 1;
                    }).catch(function (error) {
                    console.log(error);
                });
                this.choiceExercises(0);
            },

            choiceExercises(id) {
                axios.post('/exercise/list/' + id)
                    .then((response) => {
                        this.exercises = response.data.exercises;
                        this.groups = response.data.groups;
                        this.exGroup = response.data.exGroup;
                        this.markers = response.data.markers;

                        if (!id) {
                            this.allExercises = _.cloneDeep(this.exercises);
                        }

                        setTimeout(() => {
                            jQuery(this.$refs.footable).footable();
                        }, 0);

                        this.filterExercises();
                    }).catch(function (error) {
                    console.log(error);
                });
            },

            getById: function (exId) {
                let temp = this.allExercises.find((o) => {
                    return o.id === parseInt(exId);
                });
                return temp;
            },

            filterExercises() {
                let allExIds = _.map(this.exercises, 'id');
                let groupId = parseInt(this.selectFolder.id);

                if (groupId) {
                    var folderRoot = this.tree.find((item) => { return item.id === parseInt(groupId);});
                }

                if (_.has(folderRoot, 'children') && !_.isEmpty(folderRoot.children)) {
                    let noProgram = folderRoot.children.find((o) => {return o.id === groupId + '_no_folder'});

                    folderRoot.children.forEach((child) => {
                        let recursive = ((item) => {
                            item.items = _.intersection(allExIds, item.items);

                            if (_.isEmpty(item.children)) {
                                noProgram.items = _.isEmpty(item.items) ? noProgram.items : _.difference(noProgram.items, item.items);
                            } else {
                                if (!_.isEmpty(item.items)) {
                                    noProgram.items = _.difference(noProgram.items, item.items);
                                }
                                item.children.map((elem) => {
                                    recursive(elem);
                                });
                            }
                        });

                        if (child.id != noProgram.id) {
                             recursive(child)
                        } else {
                            noProgram.items = allExIds;
                        }
                    });
                } else {
                    if (allExIds.length) {
                        this.selectFolder.items = allExIds;
                    }
                }
                this.isLoaded = true;
            },

            doDelete(exId) {
                swal({
                    title: 'Are you sure?',
                    text: 'You will not be able to recover this Exercise.',
                    type: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#DD6B55',
                    confirmButtonText: 'Yes, delete it!',
                    cancelButtonText: 'Cancel'
                }, () => {
                    this.modalSubmit(exId);
                });
            },

            modalSubmit(exId) {
                swal.close();
                this.editData = _.find(this.allExercises, ['id', exId]);
                this.editData.group = this.editData.group.id;
                const self = this;

                axios.post('/exercise/delete', {
                    data: self.editData

                }).then((response) => {
                    if (response.data.success) {
                        swal({
                            title: "Exercise deleted",
                            type: "success",
                            showCancelButton: false,
                        }, () => {
                            window.location.reload();
                        });
                    } else {
                        swal({
                            title: "Error",
                            text: response.data.errors,
                            type: "warning"
                        });
                    }
                }).catch(function (error) {
                    console.log(error);
                });
            },

            getTypeStr: function (type) {
                return this.typeName[type];
            },
            onAllExerciseSelect() {
                var o = this;

                o.allExChecked = !o.allExChecked;

                if (o.allExChecked) {
                    o.checkedExercises = [];

                    o.checkedExercises = o.allExercises.map((n) => {
                        return n.id;
                    });
                } else {
                    o.checkedExercises = [];
                }
            },

            onCheckedExercises(exercise) {
                let index = _.findIndex(this.checkedExercises, (n) => {
                    return n === parseInt(exercise.id)
                });

                if (index >= 0) {
                    this.allExChecked = false;

                    _.remove(this.checkedExercises, (n) => {
                        return n === parseInt(exercise.id)
                    });
                } else {
                    this.checkedExercises.push(exercise.id);
                }

            },

            // **** tree ****
            onChangeTree(newData) {
                this.tree = newData;
                this.tree = _.cloneDeep(this.tree);
                this.onSaveTree();
            },

            onSelectedFolder(path, names, permissions) {
                let self = this;

                this.permissions = permissions;
                this.selectFolderNames = names;

                let index = _.findIndex(this.tree, (n) => {
                    return n.id == path[0];
                });

                this.selectFolder = this.findNodeByPath(this.tree[index], path.slice(1));

                path[0] === 'all_ex' ? this.choiceExercises(0) : this.choiceExercises(path[0]);
            },

            refreshCurrentFolder() {
                this.selectExercises;
            },

            findNodeByPath(node, path) {
                if (path.length === 0) {
                    return node;
                } else {
                    if (_.has(node, 'children')) {
                        let index = _.findIndex(node.children, (n) => {
                            return n.id == path[0];
                        });
                        return this.findNodeByPath(node.children[index], path.slice(1));
                    }
                }
            },

            onCopyClick() {
                if (!this.isCopyButtonDisabled) {
                    this.isCopyOrMoveClicked = true;
                    this.checkedExercises.map((elem) => {
                        let el = this.getById(elem);
                        this.copyBuffer.push(el);
                    });
                }
            },

            onPasteClick() {
                this.isCopyOrMoveClicked = false;
                this.isPasteButton = true;

                this.copyBuffer.map((elem) => {
                    let groupId = elem.group.id;

                    // if (parseInt(this.selectFolder.id) === groupId) {
                    this.selectFolder.items = _.union(this.selectFolder.items, [elem.id]);
                    this.tree = _.cloneDeep(this.tree);
                    // }
                });

                this.checkedExercises = [];
                this.copyBuffer = [];

                this.refreshCurrentFolder();
                this.onSaveTree();
            },

            onMoveClick() {
                if (!this.isMoveButtonDisabled) {
                    this.isCopyOrMoveClicked = true;
                    this.checkedExercises.map((elem) => {
                        let el = this.getById(elem);
                        this.copyBuffer.push(el);
                    });
                    this.selectFolder.items = _.difference(this.selectFolder.items, this.checkedExercises);

                    this.tree = _.cloneDeep(this.tree);
                    this.checkedExercises = [];
                    this.isPasteButton = false;
                }
                this.onSaveTree();
            },

            onDeleteClick() {
                if (!this.isDeleteButtonDisabled) {
                    this.selectFolder.items = _.difference(this.selectFolder.items, this.checkedExercises);

                    this.tree = _.cloneDeep(this.tree);
                    this.checkedExercises = [];
                }
                this.refreshCurrentFolder();
                this.onSaveTree();
            },

            onSaveTree() {
                let self = this;
                axios.post('/exercise/save-tree', {
                    tree: self.tree,
                }).then(function (response) {
                    self.$forceUpdate();
                }).catch(function (error) {
                    console.log(error);
                });
            }
        }
    }
</script>

<style scoped>
</style>
