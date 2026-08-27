<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" title="工程质量审计 → 审计规则：规则 code 与审计引擎内置规则对应（停用即不参与运行审计）；新增自定义 code 不会被引擎执行。" type="info" show-icon />

        <!-- 表格顶部菜单 -->
        <TableHeader
            :buttons="['refresh', 'add', 'delete', 'quickSearch', 'columnDisplay']"
            :quick-search-placeholder="t('Quick search placeholder', { fields: '规则标识/名称' })"
        />

        <!-- 表格 -->
        <Table ref="tableRef" />

        <!-- 表单 -->
        <PopupForm ref="formRef" />
    </div>
</template>

<script setup lang="ts">
// 审计规则管理页（baTable CRUD，模板：agent/list + ai/channel）
import { onMounted, provide, ref, useTemplateRef } from 'vue'
import { useI18n } from 'vue-i18n'
import PopupForm from './popupForm.vue'
import { baTableApi } from '/@/api/common'
import { defaultOptButtons } from '/@/components/table'
import TableHeader from '/@/components/table/header/index.vue'
import Table from '/@/components/table/index.vue'
import baTableClass from '/@/utils/baTable'
import createAxios from '/@/utils/axios'
import { ElMessage, ElMessageBox } from 'element-plus'

defineOptions({
    name: 'audit/auditrule',
})

const { t } = useI18n()
const formRef = useTemplateRef('formRef')
const tableRef = useTemplateRef('tableRef')

const fmtTs = (ts: any) => {
    if (!ts) return '-'
    const n = Number(ts)
    if (!n || n <= 0) return '-'
    const d = new Date(n * 1000)
    const pad = (x: number) => String(x).padStart(2, '0')
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds())
}

const baTable: baTableClass = new baTableClass(
    new baTableApi('/admin/audit.AuditRule/'),
    {
        dblClickNotEditColumn: [undefined],
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: 'ID', prop: 'id', align: 'center', width: '70', operator: '=' },
            {
                label: '规则标识',
                prop: 'name',
                align: 'center',
                width: '130',
                render: 'tag',
                custom: { php_syntax: 'primary', controller: 'primary', permission: 'primary', migration: 'primary', residue: 'warning', version: 'warning' },
            },
            { label: '规则名称', prop: 'title', align: 'left', minWidth: '140', operator: 'LIKE', showOverflowTooltip: true },
            { label: '规则说明', prop: 'description', align: 'left', minWidth: '260', operator: 'LIKE', showOverflowTooltip: true },
            {
                label: '状态',
                prop: 'status',
                align: 'center',
                width: '80',
                render: 'tag',
                custom: { enabled: 'success', disabled: 'info' },
                replaceValue: { enabled: '启用', disabled: '停用' },
            },
            { label: '排序', prop: 'weigh', align: 'center', width: '70' },
            { label: '创建时间', prop: 'create_time', align: 'center', width: '170', formatter: (row: anyObj) => fmtTs(row.create_time) },
            {
                label: t('Operate'),
                align: 'center',
                width: '230',
                render: 'buttons',
                buttons: [
                    ...defaultOptButtons(['edit']),
                    {
                        render: 'tipButton',
                        name: 'switch',
                        title: '启停',
                        text: '',
                        type: 'warning',
                        icon: 'fa fa-power-off',
                        class: 'table-row-switch',
                        click: (row: anyObj) => {
                            toggleStatus(row)
                        },
                    },
                    {
                        render: 'confirmButton',
                        name: 'delete',
                        title: '删除',
                        text: '',
                        type: 'danger',
                        icon: 'fa fa-trash',
                        class: 'table-row-delete',
                        popconfirm: {
                            confirmButtonText: '删除',
                            cancelButtonText: '取消',
                            confirmButtonType: 'danger',
                            title: '确认删除该规则？',
                        },
                        disabledTip: false,
                    },
                ],
                operator: false,
            },
        ],
    },
    {
        defaultItems: {
            name: '',
            title: '',
            description: '',
            status: 'enabled',
            weigh: 0,
            remark: '',
        },
    }
)

provide('baTable', baTable)

const toggleStatus = (row: anyObj) => {
    const status = row.status === 'enabled' ? 'disabled' : 'enabled'
    ElMessageBox.confirm('确认' + (status === 'enabled' ? '启用' : '停用') + '规则「' + row.title + '」？', '提示', { type: 'warning' })
        .then(async () => {
            await createAxios({ url: '/admin/audit.AuditRule/switch', method: 'post', data: { id: row.id, status } })
            ElMessage.success('操作成功')
            baTable.onTableHeaderAction('refresh')
        })
        .catch(() => {})
}

onMounted(() => {
    baTable.table.ref = tableRef.value
    baTable.mount()
    baTable.getData()
})
</script>

<style scoped>
.table-row-switch {
    color: var(--el-color-warning) !important;
}
.table-row-delete {
    color: var(--el-color-danger) !important;
}
</style>