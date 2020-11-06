<template>
    <div class="create-popup">
        <div class="overlay"></div>
        <div class="create-popup-inner">
            <button class="close m-r-sm m-t-sm" @click="$emit('close')"><span aria-hidden="true">&times;</span>
            </button>
            <div class="p-md">
                <div class="row">
                    <div class="col-md-12">
                        <h3>{{ modalTitle }}</h3>

                        <div v-if="modalErrors.length">
                            <div class="alert alert-danger">
                                <ul>
                                    <li v-for="error in modalErrors">{{ error }}</li>
                                </ul>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="group">Exercise Group *</label>
                            <select id="group" class="form-control" v-model="modalData.group"
                                    :disabled="isFaEditBase" required>
                                <option v-for="(gr, grId) in groups" v-bind:value="gr.id">{{ gr.name }}
                                </option>
                            </select>
                        </div>

                        <div class="form-group" v-if="isAutorAdmin && !isFaEditBase">
                            <div class="row">
                                <div class="col-md-6">
                                    <input id="type1" class="i-checks" type="radio" value="1" v-model="modalData.type">
                                    <label for="type1">Organization Base</label>
                                </div>
                                <div class="col-md-6">
                                    <input id="type2" class="i-checks" type="radio" value="2" v-model="modalData.type">
                                    <label for="type2">Ordinary</label>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="name">Exercise Name *</label>
                            <input id="name" type="text" class="form-control" v-model="modalData.name"
                                   :disabled="isFaEditBase" required>
                        </div>

                        <div class="row">
                            <div class="col-10">
                                <div class="form-group">
                                    <label for="link">
                                        <span v-if="isFaEditBase">Base </span>
                                        Link
                                    </label>
                                    <input id="link" type="text" class="form-control"
                                           v-model="modalData.link" :disabled="isFaEditBase">
                                </div>
                            </div>
                            <div class="col-2 m-t-lg" v-if="isFaEditBase">
                                <input type="radio" class="i-checks" name="link_type" value="0"
                                       v-model="modalData.allowLocalLink">
                            </div>
                        </div>

                            <div class="row">
                                <div class="col-10">
                                    <div class="form-group" v-if="isFaEditBase">
                                        <label for="local_link">Local Link</label>
                                        <input id="local_link" type="text" class="form-control"
                                               v-model="modalData.localLink">
                                    </div>
                                </div>
                                <div class="col-2 m-t-lg" v-if="isFaEditBase">
                                    <input type="radio" name="link_type" value="1"
                                           v-model="modalData.allowLocalLink">
                                </div>
                            </div>

                        <div class="row">
                            <div class="col-10">
                                <div class="form-group">
                                    <label for="description">
                                        <span v-if="isFaEditBase">Base </span>
                                        Description
                                    </label>
                                    <textarea id="description" class="form-control"
                                              v-model="modalData.description"
                                              :disabled="isFaEditBase">
                                            </textarea>
                                </div>
                            </div>
                            <div class="col-2 m-t-lg" v-if="isFaEditBase">
                                <input type="radio" class="i-checks" name="descr_type" value="0"
                                       v-model="modalData.allowLocalDescription">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-10">
                                <div class="form-group" v-if="isFaEditBase">
                                    <label for="localDescription">Local Description</label>
                                    <textarea id="localDescription" class="form-control"
                                              v-model="modalData.localDescription">
                                            </textarea>
                                </div>
                            </div>
                            <div class="col-2 m-t-lg" v-if="isFaEditBase">
                                <input type="radio" class="i-checks" name="descr_type" value="1"
                                       v-model="modalData.allowLocalDescription">
                            </div>
                        </div>

                        <div class="form-group">
                            <input type="checkbox" class="i-checks" id="published" v-model="modalData.is_published">
                            <label for="published">Publish</label>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <button class="btn btn-default btn-block" @click="$emit('close')">
                                    Cancel
                                </button>
                            </div>
                            <div class="col-md-6">
                                <button class="ladda-button ladda-button-demo btn btn-primary btn-block"
                                        data-style="zoom-in"
                                        @click="modalSave()">Save
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
    import axios from 'axios';

    export default {
        name: "ModalFormExercise",

        props: {
            propData: {
                type: Object,
            },
            propIsAction: {
                type: String,
            },
        },

        data: function () {
            return {
                modalData: this.propData,
                modalErrors: [],
                isBusy: null,
                roleId: 1,
                allExercises: null,
                groups: null,
                exGroup: null,
                markers: null,
                isLoaded: false,
                validName: false,
                isSave: null
            }
        },
        mounted: function () {
            this.reload();
        },

        computed: {
            modalTitle() {
                return this.propIsAction + ' Exercise';
            },

            userCan() {
                return (marker) => {
                    return _.includes(this.markers, marker);
                }
            },

            isAutorAdmin() {
                return this.userCan('@facility_admin') || this.userCan('@chief_facility_trainer');
            },

            isFaEditBase() {
                return this.modalData.type === 0 && this.isAutorAdmin && this.propIsAction === 'Edit';
            },

            assignedGroup() {
                return (groupID) => {
                    let groupEx = _.find(this.exGroup, ['id', groupID]);
                    let group = null;

                    if (groupEx) {
                        group = _.find(this.groups, ['group_exercise', groupEx.group]);
                    } else {
                        group = _.find(this.groups, ['isUngroup', true]);
                    }

                    return group;
                }
            },

            contains() {
                return (exName, exId = 0) => {
                    let exercise = [];
                    if (exId !== 0) {
                        let obj = _.find(this.allExercises, (el) => {
                            el.id !== parseInt(exId) && el.name === exName
                        });
                        _.has(obj, 'id') ? exercise.push(obj.id) : ''
                    } else {
                        let obj = _.find(this.allExercises, ['name', exName]);
                        _.has(obj, 'id') ? exercise.push(obj.id) : ''
                    }
                    return exercise.length > 0;
                }
            },
        },

        methods: {
            reload() {
                this.isLoaded = false;
                const self = this;

                axios.post('/exercise/list/' + 0)
                    .then(function (response) {
                        self.allExercises = response.data.exercises;
                        self.groups = response.data.groups;
                        self.roleId = response.data.roleId;
                        self.exGroup = response.data.exGroup;
                        self.markers = response.data.markers;
                        self.isLoaded = true;
                    }).catch(function (error) {
                    console.log(error);
                });
            },

            modalSave() {
                if (!this.isValid()) {
                    return;
                }

                if (this.isFaEditBase) {
                    this.modalData.link = this.modalData.allowLocalLink == 0 ? this.modalData.link : this.modalData.localLink;
                    this.modalData.description = this.modalData.allowLocalDescription == 0 ? this.modalData.description : this.modalData.localDescription;
                }

                if (_.has(this.modalData, 'id')) {
                    this.validName = this.contains(this.modalData.name, this.modalData.id);
                } else {
                    this.validName = this.contains(this.modalData.name);
                }
                let saveButton = $('button.ladda-button');
                this.isSave = Ladda.create(saveButton[0]);
                this.isSave.start();

                if (this.validName) {
                    swal({
                        title: 'Are you sure?',
                        text: 'An Exercise with this name already exists.',
                        type: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#DD6B55',
                        confirmButtonText: 'Yes, Create!',
                        cancelButtonText: 'Cancel'
                    }, () => {
                        this.modalSubmit();
                    });
                } else {
                    this.modalSubmit();
                }
            },

            isValid() {
                this.modalErrors = [];

                if (this.modalData.name === '') {
                    this.modalErrors.push('Name cannot be empty');
                }

                if (this.modalData.group === 0) {
                    this.modalErrors.push('Please, select exercise group');
                }

                return this.modalErrors.length === 0;
            },

            modalSubmit: function () {
                // if (this.validName) {
                //     swal.close();
                // }

                const self = this;
                axios.post(self.modalData.saveUrl, {
                    data: self.modalData
                }).then(function (response) {
                    if (response.data.success) {
                        swal({
                            title: (self.propIsAction === 'Edit') ? "Exercise saved" : "Exercise created",
                            type: "success",
                            showCancelButton: false,
                        }, () => {
                            self.isSave.stop();
                            self.$emit('close');
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
        }
    }
</script>

<style scoped>
    .create-popup {
        z-index: 10000;
        width: 100%;
        height: 100%;
        position: fixed;
        top: 0;
        left: 0;
    }

    .create-popup .overlay {
        position: absolute;
        z-index: 10001;
        width: 100%;
        height: 100%;
        background: #333;
        opacity: 0.9;
        top: 0;
        left: 0;
    }

    .create-popup-inner {
        z-index: 10002;
        position: relative;
        width: 400px;
        max-height: 100%;
        margin: 15px auto 0;
        background: #fff;
        overflow-y: auto;
    }
</style>