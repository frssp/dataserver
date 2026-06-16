# 매뉴얼 스크린샷 / Manual screenshots

이 폴더에 PNG를 올리면 `/manual.html`의 해당 자리(figure 슬롯)에 자동으로 표시됩니다.
파일이 없으면 그 자리에 점선 자리표시(placeholder)가 보이고, 업로드하면 사진으로 바뀝니다.

Drop PNG files here and they appear in the matching figure slots on `/manual.html`.
Until a file exists, a dashed placeholder is shown; once uploaded it becomes the image.

## 기대하는 파일명 / Expected filenames

| 파일명 (filename)                       | 내용 (what to capture)                                                      |
|-----------------------------------------|-----------------------------------------------------------------------------|
| `config-editor-api-url.png`             | 3-① 환경설정 편집기에서 `api.url`(끝 슬래시 포함)을 설정한 화면              |
| `config-editor-steaming-enabled.png`    | 3-① 환경설정 편집기에서 `streaming.enabled = false`를 설정한 화면           |

> 슬롯은 필요할 때 더 추가할 수 있습니다(예: 동기화 로그인 화면). 지금은 3-①만 연결되어 있습니다.

## 팁 / Tips

- 가로 폭은 1000px 내외면 충분합니다(페이지에서 100%로 축소 표시).
- 민감한 정보(실제 비밀번호 등)가 화면에 없도록 주의하세요.
- 같은 이미지가 한국어·영어 양쪽 화면에서 공유됩니다(캡션만 언어별로 다름).
