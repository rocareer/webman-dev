<template>
    <!-- 审计项目编辑弹窗 -->
    <el-dialog
        class="ba-operate-dialog"
        :close-on-click-modal="false"
        :model-value="['Add', 'Edit'].includes(baTable.form.operate!)"
        @close="baTable.toggleForm"
        :destroy-on-close="true"
        width="640px"
    >
        <template #header>
            <div class="title" v-drag="['.ba-operate-dialog', '.el-dialog__header']" v-zoom="'.ba-operate-dialog'">
                {{ baTable.form.operate == 'Add' ? '新增审计项目' : '编辑审计项目' }}
            </div>
        </template>
        <el-scrollbar v-loading="baTable.form.loading" class="ba-table-form-scrollbar">
            <div
                class="ba-operate-form"
                :class="'ba-' + baTable.form.operate + '-form'"
                :style="config.layout.shrink ? '' : 'width: calc(100% - ' + baTable.form.labelWidth! / 2 + 'px)'"
            >
                <el-form
                    ref="formRef"
                    @keyup.enter="baTable.onSubmit(formRef)"
                    :model="baTable.form.items"
                    :label-position="config.layout.shrink ? 'top' : 'right'"
                    :label-width="baTable.form.labelWidth + 'px'"
                    :rules="rules"
                    v-if="!baTable.form.loading"
                >
                    <el-form-item prop="name" label="包目录名">
                        <el-input v-model="baTable.form.items!.name" placeholder="如 radmin / ai / agent" :disabled="baTable.form.operate === 'Edit'" />
                        <div class="form-tip">工作区 src 根下的包目录名（OIDC 注意大小写），运行审计按此目录扫描</div>
                    </el-form-item>
                    <el-form-item prop="title" label="项目名称">
                        <el-input v-model="baTable.form.items!.title" placeholder="显示名称，如 AI 路由" />
                    </el-form-item>
                    <el-form-item label="状态">
                        <el-radio-group v-model="baTable.form.items!.status">
                            <el-radio value="enabled">启用</el-radio>
                            <el-radio value="disabled">停用（不参与运行审计）</el-radio>
                        </el-radio-group>
                    </el-form-item>
                    <el-form-item label="排序">
                        <el-input-number v-model="baTable.form.items!.weigh" :min="0" :max="9999" style="width: 100%" />
                    </el-form-item>
                    <el-form-item label="备注">
                        <el-input v-model="baTable.form.items!.remark" type="textarea" :rows="2" />
                    </el-form-item>
                    <el-alert
                        v-if="baTable.form.operate === 'Edit' && baTable.form.items!.last_run_at"
                        type="info"
                        :closable="false"
                        show-icon
                        title="最近一轮审计快照（问题数/未通过规则）在下次运行审计时自动更新，此处不可编辑。"
                    />
                </el-form>
            </div>
        </el-scrollbar>
        <template #footer>
            <div :style="'width: calc(100% - ' + baTable.form.labelWidth! / 1.8 + 'px)'">
                <el-button @click="baTable.toggleForm('')">{{ t('Cancel') }}</el-button>
                <el-button v-blur :loading="baTable.form.submitLoading" @click="submitForm" type="primary">
                    {{ t('Save') }}
                </el-button>
            </div>
        </template>
    </el-dialog>
</template>

<script setup lang="ts">
// 审计项目编辑弹窗（模板：agent/list/popupForm 简化版）
import { inject, useTemplateRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { useConfig } from '/@/stores/config'
import { ElMessage } from 'element-plus'
import type baTableClass from '/@/utils/baTable'

const { t } = useI18n()
const baTable: baTableClass = inject('baTable')!
const formRef = useTemplateRef('formRef')
const config = useConfig()

const rules = {
    name: [{ required: true, message: '请填写包目录名', trigger: 'blur' }],
    title: [{ required: true, message: '请填写项目名称', trigger: 'blur' }],
}

const submitForm = async () => {
    const items: any = baTable.form.items
    if (!items.name || !items.title) {
        ElMessage.warning('请填写包目录名和项目名称')
        return
    }
    baTable.onSubmit(formRef.value)
}
</script>