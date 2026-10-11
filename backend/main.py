import logging

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field
from app.services.archive_service import audit, repair, ArchiveError
from app.metadata_api import router as metadata_router

from app.services.date_parser import find_document_date
from app.services.autokat import analyze_autokat
from app.services.text_compare import analyze_text_compare
from app.services.export_service import prepare_single_export, prepare_full_export

APP_NAME = "e_ablage Backend API"
APP_VERSION = "4.3.0"

app = FastAPI(
    title=APP_NAME,
    version=APP_VERSION
)
app.include_router(metadata_router)
from app.inbox_api import router as inbox_router
app.include_router(inbox_router)


class DateAnalysisRequest(BaseModel):
    text: str
    search_text: str = ""


class AutoKatRequest(BaseModel):
    text: str


class TextCompareRequest(BaseModel):
    text: str
    exclude_document_id: int | None = None


@app.get("/health")
def health():
    return {
        "status": "ok",
        "service": "eablage-backend",
        "version": APP_VERSION
    }


@app.post("/analyze/date")
def analyze_date(request: DateAnalysisRequest):
    result = find_document_date(
        text=request.text,
        search_text=request.search_text
    )

    return {
        "date": result,
        "found": result is not None
    }


@app.post("/analyze/autokat")
def analyze_autokat_endpoint(request: AutoKatRequest):
    return analyze_autokat(request.text)


@app.post("/analyze/textcompare")
def analyze_text_compare_endpoint(request: TextCompareRequest):
    return analyze_text_compare(request.text, request.exclude_document_id)


@app.get("/export/document/{dok_id}")
def export_document(dok_id: int):
    try:
        return prepare_single_export(dok_id)
    except LookupError as exc:
        raise HTTPException(status_code=404, detail=str(exc)) from exc
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc


@app.get("/export/plan")
def export_plan():
    try:
        return prepare_full_export()
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc



# Archive maintenance is called by PHP through the internal Docker network.


class ArchiveRequest(BaseModel):
    database: str = Field(min_length=1, max_length=64)


class RepairSelection(BaseModel):
    dok_id: int = Field(gt=0)
    token: str = Field(min_length=1, max_length=100)


class ArchiveRepairRequest(ArchiveRequest):
    selections: list[RepairSelection] = Field(min_length=1, max_length=100)


@app.post('/archive/audit')
def archive_audit(request: ArchiveRequest):
    try:
        return audit(request.database)
    except ArchiveError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc
    except Exception as exc:
        logging.exception('Archivprüfung fehlgeschlagen')
        raise HTTPException(status_code=500, detail='Archivprüfung fehlgeschlagen. Datenbank, Mounts und Leserechte im Backend prüfen.') from exc


@app.post('/archive/repair')
def archive_repair(request: ArchiveRepairRequest):
    if len({item.dok_id for item in request.selections}) != len(request.selections):
        raise HTTPException(status_code=400, detail='Dokumente dürfen nur einmal ausgewählt werden.')
    try:
        return repair(request.database, [item.model_dump() for item in request.selections])
    except ArchiveError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc
    except Exception as exc:
        logging.exception('Metadatenreparatur unterbrochen')
        raise HTTPException(status_code=500, detail='Reparatur unterbrochen. Einzelne Dateien können bereits repariert sein; bitte erneut prüfen.') from exc

from app.services.master_data import list_entries, mutate, MasterDataError


class MasterDataRequest(BaseModel):
    database: str = Field(min_length=1, max_length=64)
    action: str = "list"
    entry_id: str = Field(default="", max_length=20)
    name: str = Field(default="", max_length=255)


@app.post("/master-data/{kind}")
def master_data(kind: str, request: MasterDataRequest):
    try:
        if request.action == "list":
            return list_entries(kind, request.database)
        return mutate(kind, request.database, request.action, request.entry_id, request.name)
    except MasterDataError as exc:
        raise HTTPException(status_code=409, detail={"message": str(exc), "rules": exc.rules}) from exc
    except Exception as exc:
        logging.exception("Stammdatenverwaltung fehlgeschlagen")
        raise HTTPException(status_code=500, detail={"message": "Stammdaten konnten nicht verarbeitet werden. SQL-Update, Datenbankrechte und Backend-Log prüfen.", "rules": []}) from exc
